<?php

namespace App\Domains\Banking\Services\Statements;

use App\Domains\Investments\Services\Statements\PdfTextExtractor;
use App\Domains\Investments\Services\Statements\TabularFileReader;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Leitor genérico de extrato/fatura: CSV, XLSX (sem cabeçalho fixo), PDF com texto e foto (via IA, se configurada).
 * Só lê: devolve linhas {date, description, amount (com sinal), balance?}. Nunca grava nada.
 */
class GenericStatementReader
{
    public const MAX_ROWS = 3000;

    private const KEYWORDS = [
        'date' => ['data', 'dt', 'date', 'movimenta', 'lançamento em', 'dia'],
        'description' => ['descri', 'histór', 'histor', 'lançamento', 'lancamento', 'memo', 'estabelecimento', 'detalhe', 'título', 'titulo'],
        'amount' => ['valor', 'montante', 'quantia', 'amount', 'r$'],
        'debit' => ['débito', 'debito', 'saída', 'saida'],
        'credit' => ['crédito', 'credito', 'entrada'],
        'balance' => ['saldo', 'balance'],
    ];

    public function __construct(private TabularFileReader $tabular, private PdfTextExtractor $pdf, private StatementImageReader $image) {}

    /** @return array{rows: list<array{date: string, description: string, amount: float, balance: ?float}>, source: string, mapping: array<string, int|null>, skipped: int, warnings: list<string>} */
    public function read(string $path, string $extension): array
    {
        $ext = strtolower($extension);

        return match (true) {
            in_array($ext, ['csv', 'txt'], true) => $this->fromTable($this->tabular->rawRows($path, 'csv'), 'CSV'),
            $ext === 'xlsx' => $this->fromTable($this->tabular->rawRows($path, 'xlsx'), 'XLSX'),
            $ext === 'pdf' => $this->fromPdf($path),
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => $this->fromImage($path, $ext),
            default => throw new RuntimeException('Formato não suportado. Use CSV, XLSX, PDF, JPG ou PNG.'),
        };
    }

    /** @param list<list<string>> $raw */
    public function fromTable(array $raw, string $label): array
    {
        $raw = array_slice($raw, 0, self::MAX_ROWS + 200);
        [$headerIndex, $map] = $this->findHeader($raw);
        $source = $label.' (cabeçalho na linha '.($headerIndex + 1).')';
        if ($map === null) {
            $map = $this->inferColumns($raw);
            $headerIndex = -1;
            $source = $label.' (sem cabeçalho: colunas identificadas pelo conteúdo)';
        }
        if ($map['date'] === null || ($map['amount'] === null && $map['debit'] === null && $map['credit'] === null)) {
            throw new RuntimeException('Não consegui identificar as colunas de data e valor neste arquivo.');
        }
        $rows = [];
        $skipped = 0;
        foreach (array_slice($raw, $headerIndex + 1) as $r) {
            $date = $this->parseDate($r[$map['date']] ?? '');
            $amount = null;
            if ($map['amount'] !== null) {
                $amount = $this->parseMoney($r[$map['amount']] ?? '');
            }
            if ($amount === null && ($map['debit'] !== null || $map['credit'] !== null)) {
                $deb = $map['debit'] !== null ? $this->parseMoney($r[$map['debit']] ?? '') : null;
                $cre = $map['credit'] !== null ? $this->parseMoney($r[$map['credit']] ?? '') : null;
                if ($deb !== null && abs($deb) > 0) {
                    $amount = -abs($deb);
                } elseif ($cre !== null) {
                    $amount = abs($cre);
                }
            }
            $desc = $map['description'] !== null ? trim((string) ($r[$map['description']] ?? '')) : '';
            if ($date === null || $amount === null || abs($amount) < 0.005 || self::isBalanceLine($desc)) {
                $skipped++;

                continue;
            }
            $rows[] = [
                'date' => $date,
                'description' => $desc !== '' ? preg_replace('/\s+/', ' ', $desc) : 'Sem descrição',
                'amount' => round($amount, 2),
                'balance' => $map['balance'] !== null ? $this->parseMoney($r[$map['balance']] ?? '') : null,
            ];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Nenhum lançamento com data e valor foi encontrado no arquivo.');
        }

        return ['rows' => $rows, 'source' => $source, 'mapping' => $map, 'skipped' => $skipped, 'warnings' => []];
    }

    /** @return array{0: int, 1: array<string, int|null>|null} */
    private function findHeader(array $raw): array
    {
        $best = [-1, null, 0];
        foreach (array_slice($raw, 0, 80, true) as $i => $r) {
            $map = ['date' => null, 'description' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'balance' => null];
            $score = 0;
            foreach ($r as $c => $cell) {
                $cell = mb_strtolower(trim((string) $cell));
                if ($cell === '' || mb_strlen($cell) > 40) {
                    continue;
                }
                foreach (['balance', 'debit', 'credit', 'date', 'amount', 'description'] as $field) {
                    if ($map[$field] !== null) {
                        continue;
                    }
                    foreach (self::KEYWORDS[$field] as $kw) {
                        if (str_starts_with($cell, $kw) || ($field !== 'date' && str_contains($cell, $kw))) {
                            $map[$field] = $c;
                            $score++;
                            continue 3;
                        }
                    }
                }
            }
            if ($map['date'] !== null && ($map['amount'] !== null || $map['debit'] !== null || $map['credit'] !== null) && $score > $best[2]) {
                $best = [$i, $map, $score];
            }
        }

        return [$best[0], $best[1]];
    }

    /** Sem cabeçalho: data = coluna com mais datas; valor = primeira coluna monetária; saldo = outra monetária; descrição = texto mais longo. */
    private function inferColumns(array $raw): array
    {
        $stats = [];
        foreach ($raw as $r) {
            foreach ($r as $c => $cell) {
                $cell = trim((string) $cell);
                if ($cell === '') {
                    continue;
                }
                $stats[$c]['date'] = ($stats[$c]['date'] ?? 0) + ($this->parseDate($cell) !== null ? 1 : 0);
                $isMoney = $this->parseMoney($cell) !== null && $this->parseDate($cell) === null;
                $stats[$c]['money'] = ($stats[$c]['money'] ?? 0) + ($isMoney ? 1 : 0);
                $stats[$c]['text'] = ($stats[$c]['text'] ?? 0) + (! $isMoney && $this->parseDate($cell) === null ? mb_strlen($cell) : 0);
            }
        }
        $pick = function (string $k, array $exclude = []) use ($stats) {
            $best = null;
            $max = 0;
            foreach ($stats as $c => $s) {
                if (! in_array($c, $exclude, true) && ($s[$k] ?? 0) > $max) {
                    $max = $s[$k];
                    $best = $c;
                }
            }

            return $max >= 2 ? $best : null;
        };
        $date = $pick('date');
        $money = [];
        foreach ($stats as $c => $s) {
            if ($c !== $date && ($s['money'] ?? 0) >= 2) {
                $money[] = $c;
            }
        }
        sort($money);

        return [
            'date' => $date,
            'description' => $pick('text', array_merge([$date], $money)),
            'amount' => $money[0] ?? null,
            'debit' => null,
            'credit' => null,
            'balance' => $money[1] ?? null,
        ];
    }

    /** Linhas de saldo (Saldo inicial/anterior/do dia) não são lançamentos. */
    public static function isBalanceLine(string $desc): bool
    {
        return (bool) preg_match('/^\s*(saldo|s a l d o)\b/iu', $desc);
    }

    public function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 30000 && (float) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $v))->toDateString();
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})(\s|$|T)/', $v, $m)) {
            $y = (int) $m[3];
            $y = $y < 100 ? 2000 + $y : $y;

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }

        return null;
    }

    public function parseMoney(string $v): ?float
    {
        $v = trim(str_replace(["\u{a0}", ' '], '', $v));
        if ($v === '' || ! preg_match('/\d/', $v)) {
            return null;
        }
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $neg = true;
            $v = $m[1];
        }
        if (preg_match('/^(.*?)([DC])$/i', $v, $m)) {
            $neg = strtoupper($m[2]) === 'D';
            $v = $m[1];
        }
        $v = str_ireplace('R$', '', $v);
        if (str_contains($v, '-')) {
            $neg = true;
            $v = str_replace('-', '', $v);
        }
        $v = str_replace('+', '', $v);
        if (! preg_match('/^[\d.,]+$/', $v)) {
            return null;
        }
        if (preg_match('/,\d{1,2}$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/\.\d{1,2}$/', $v) || preg_match('/^\d+$/', $v)) {
            $v = str_replace(',', '', $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }
        if (! is_numeric($v)) {
            return null;
        }

        return $neg ? -(float) $v : (float) $v;
    }

    private function fromPdf(string $path): array
    {
        $res = $this->pdf->extract($path);
        if ($res['status'] !== 'text_extracted') {
            throw new RuntimeException($res['status'] === 'no_text'
                ? 'Este PDF não tem texto (parece escaneado). Envie como foto (JPG/PNG) ou exporte em CSV/XLSX.'
                : 'Não consegui ler este PDF.');
        }

        return $this->fromText($res['text'], 'PDF');
    }

    /** Linhas de texto que começam com data e terminam com valor(es): "05/10/2026  POSTO X  -150,17  1.234,00". */
    public function fromText(string $text, string $label): array
    {
        $rows = [];
        $skipped = 0;
        $money = '\(?-?\s?(?:R\$\s?)?-?\d{1,3}(?:\.\d{3})*,\d{2}\)?(?:\s?[DC])?';
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $line = trim($line);
            if (! preg_match('/^(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)\s+(.+?)\s+('.$money.')(?:\s+('.$money.'))?\s*$/u', $line, $m)) {
                if (preg_match('/^\d{1,2}\/\d{1,2}/', $line)) {
                    $skipped++;
                }

                continue;
            }
            $d = $m[1];
            if (substr_count($d, '/') === 1) {
                $d .= '/'.now()->year;
            }
            $date = $this->parseDate($d);
            $amount = $this->parseMoney($m[3]);
            if ($date === null || $amount === null || self::isBalanceLine($m[2])) {
                $skipped++;

                continue;
            }
            $rows[] = ['date' => $date, 'description' => preg_replace('/\s{2,}/', ' ', trim($m[2])), 'amount' => round($amount, 2), 'balance' => isset($m[4]) && $m[4] !== '' ? $this->parseMoney($m[4]) : null];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Não encontrei lançamentos (linhas com data e valor) no '.$label.'.');
        }

        return ['rows' => $rows, 'source' => $label.' (texto)', 'mapping' => [], 'skipped' => $skipped, 'warnings' => ['Leitura de PDF por texto: confira datas e valores antes de aprovar.']];
    }

    private function fromImage(string $path, string $ext): array
    {
        $rows = $this->image->read($path, $ext);

        return ['rows' => $rows, 'source' => 'Foto (leitura por IA)', 'mapping' => [], 'skipped' => 0, 'warnings' => ['Leitura de foto por IA: confira cada linha antes de aprovar.']];
    }
}

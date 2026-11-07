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
            in_array($ext, ['csv', 'txt'], true) && self::isCaixaTxt((string) file_get_contents($path, false, null, 0, 512)) => $this->fromCaixaTxt((string) file_get_contents($path)),
            in_array($ext, ['csv', 'txt'], true) => $this->fromTable($this->tabular->rawRows($path, 'csv'), 'CSV'),
            $ext === 'xlsx' => $this->fromTable($this->tabular->rawRows($path, 'xlsx'), 'XLSX'),
            $ext === 'pdf' => $this->fromPdf($path),
            in_array($ext, ['html', 'htm'], true) => $this->fromHtml((string) file_get_contents($path)),
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => $this->fromImage($path, $ext),
            default => throw new RuntimeException('Formato não suportado. Use OFX, CSV, XLSX, PDF, HTML, JPG ou PNG.'),
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
        // Fatura com adicionais: coluna "Final Cartão" (pode mudar de posição a cada seção da planilha).
        $finalCol = null;
        $summary = ['invoice_total' => null, 'previous_balance' => null];
        $scanHeader = function (array $r) use (&$finalCol, &$summary): bool {
            $isHeader = false;
            foreach ($r as $ci => $cell) {
                $c = mb_strtolower(trim((string) $cell));
                if (preg_match('/^final\s*(do\s*)?cart/u', $c)) {
                    $finalCol = $ci;
                    $isHeader = true;
                }
                foreach (['invoice_total' => '/^total\s+da\s+fatura/u', 'previous_balance' => '/^saldo\s+(da\s+)?fatura\s+anterior/u'] as $k => $re) {
                    if (preg_match($re, $c)) {
                        foreach (array_slice($r, $ci + 1, null, true) as $v) {
                            $n = is_numeric($v) ? (float) $v : $this->parseMoney((string) $v);
                            if ($n !== null && trim((string) $v) !== '') {
                                $summary[$k] = round($n, 2);
                                break;
                            }
                        }
                    }
                }
            }

            return $isHeader;
        };
        foreach (array_slice($raw, 0, max(0, $headerIndex + 1)) as $r) {
            $scanHeader($r);
        }
        foreach (array_slice($raw, $headerIndex + 1) as $r) {
            if ($scanHeader($r)) {
                continue;
            }
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
                'final' => $finalCol !== null && preg_match('/^\D*(\d{4})\D*$/', trim((string) ($r[$finalCol] ?? '')), $fm) ? $fm[1] : null,
            ];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Nenhum lançamento com data e valor foi encontrado no arquivo.');
        }

        return ['rows' => $rows, 'source' => $source, 'mapping' => $map, 'skipped' => $skipped, 'warnings' => [], 'summary' => $summary];
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
        if ($res['status'] === 'no_text') {
            $ocr = $this->pdf->ocr($path);
            if ($ocr['status'] === 'text_extracted') {
                return $this->fromOcrText($ocr['text']);
            }
        }
        if ($res['status'] !== 'text_extracted') {
            throw new RuntimeException($res['status'] === 'no_text'
                ? 'Este PDF não tem texto (parece escaneado) e a leitura por OCR não encontrou lançamentos. Envie como foto (JPG/PNG) ou exporte em CSV/XLSX/OFX.'
                : 'Não consegui ler este PDF.');
        }

        return $this->fromText($res['text'], 'PDF');
    }

    /** TXT da Caixa: "Conta";"Data_Mov";"Nr_Doc";"Historico";"Valor";"Deb_Cred" (data AAAAMMDD, valor com ponto). */
    public static function isCaixaTxt(string $head): bool
    {
        return (bool) preg_match('/Data_Mov.{0,40}Nr_Doc.{0,80}Deb_Cred/is', $head);
    }

    public function fromCaixaTxt(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        $head = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), ';', '"', ''));
        $ix = array_flip($head);
        $rows = [];
        $skipped = 0;
        $ignored = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $c = str_getcsv($line, ';', '"', '');
            $d = trim($c[$ix['data_mov']] ?? '');
            $desc = trim($c[$ix['historico']] ?? '');
            $val = trim($c[$ix['valor']] ?? '');
            $dc = strtoupper(trim($c[$ix['deb_cred']] ?? ''));
            if (self::isBalanceLine($desc)) {
                $ignored++;

                continue;
            }
            if (! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) || ! is_numeric($val) || ! in_array($dc, ['C', 'D'], true)) {
                $skipped++;

                continue;
            }
            $rows[] = ['date' => "$m[1]-$m[2]-$m[3]", 'description' => $desc ?: 'Sem descrição', 'amount' => round(($dc === 'D' ? -1 : 1) * (float) $val, 2), 'balance' => null, 'doc' => trim($c[$ix['nr_doc']] ?? '')];
        }
        if ($rows === []) {
            throw new RuntimeException('Não encontrei lançamentos neste TXT da Caixa.');
        }

        return ['rows' => $rows, 'source' => 'TXT Caixa', 'mapping' => [], 'skipped' => $skipped, 'warnings' => [], 'ignored_balance_lines' => $ignored];
    }

    /** Arquivo é página HTML (ex.: extrato salvo do internet banking), qualquer que seja a extensão (.html, .htm, .bin). */
    public static function looksLikeHtml(string $head): bool
    {
        return (bool) preg_match('/<\s*(html|table|!doctype\s+html)\b/i', $head);
    }

    /**
     * Extrato salvo como HTML (ex.: Caixa "Extrato por período": Data Mov. | Nr. Doc. | Histórico | Valor C/D | Saldo C/D).
     * Aceita iso-8859-1 ou UTF-8. Linhas SALDO ANTERIOR / SALDO DIA são ignoradas (o saldo anterior vira referência).
     */
    public function fromHtml(string $html): array
    {
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $html = preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);
        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $money = '/^\(?-?(?:R\$\s?)?\d{1,3}(?:\.\d{3})*,\d{2}\)?\s?[DC]?$/u';
        $rows = [];
        $skipped = 0;
        $ignored = 0;
        $opening = null;
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $td->textContent)));
                }
            }
            $cells = array_values(array_filter($cells, fn ($c) => $c !== ''));
            if (count($cells) < 3) {
                continue;
            }
            $date = null;
            $desc = '';
            $doc = null;
            $vals = [];
            foreach ($cells as $c) {
                if ($doc === null && preg_match('/^\d{4,10}$/', $c)) {
                    $doc = $c;
                } elseif ($date === null && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $c)) {
                    $date = $this->parseDate($c);
                } elseif (preg_match($money, $c)) {
                    $vals[] = $c;
                } elseif ($desc === '' && preg_match('/\p{L}/u', $c)) {
                    $desc = $c;
                }
            }
            if ($vals === [] || $desc === '') {
                continue;
            }
            if (self::isBalanceLine($desc)) {
                if (preg_match('/anterior/iu', $desc)) {
                    $opening = $this->parseMoney(end($vals));
                }
                $ignored++;

                continue;
            }
            $amount = $this->parseMoney($vals[0]);
            if ($date === null || $amount === null) {
                $skipped++;

                continue;
            }
            if (! preg_match('/[DC]$/', $vals[0]) && ! str_contains($vals[0], '-')) {
                $skipped++; // sem C/D não dá para saber o sinal

                continue;
            }
            $rows[] = ['date' => $date, 'description' => $desc, 'amount' => round($amount, 2), 'balance' => count($vals) > 1 ? $this->parseMoney(end($vals)) : null, 'doc' => $doc];
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Não encontrei lançamentos (linhas com data e valor) neste HTML.');
        }

        return ['rows' => $rows, 'source' => 'HTML', 'mapping' => [], 'skipped' => $skipped, 'warnings' => [], 'opening_balance' => $opening, 'ignored_balance_lines' => $ignored];
    }

    /** Valor lido por OCR: só os dígitos contam e os 2 últimos são centavos ("5,167,18D" → 5167,18; "50.000,/00" → 50000,00). */
    public static function ocrMoney(string $token): ?float
    {
        $digits = preg_replace('/\D/', '', $token);
        if (strlen($digits) < 3) {
            return null;
        }

        return round(((int) $digits) / 100, 2);
    }

    /**
     * Extrato lido por OCR (ex.: Caixa): "08/10/2026 - 17:01:38 003151 CRED LEVANT JUDICIAL 30.181,36 C 97.069,38 C".
     * Penúltimo valor = lançamento (D = saída, C = entrada), último = saldo. Linhas SALDO são ignoradas.
     * Se o saldo da linha não fecha com o anterior, o valor é recalculado pela diferença de saldos e avisado.
     */
    public function fromOcrText(string $text): array
    {
        $money = '\d[\d.,\/]*\d\s?[DC]';
        $opening = null;
        if (preg_match('/SALDO\s+ANTERIOR[^\d]*([\d][\d.,\/]*\d)\s?([DC])?/iu', $text, $m)) {
            $opening = self::ocrMoney($m[1]) * (($m[2] ?? 'C') === 'D' ? -1 : 1);
        }
        $rows = [];
        $skipped = 0;
        $ignored = 0;
        foreach (preg_split('/\r\n|\n|\r/', $text) as $i => $line) {
            $line = trim($line);
            if (! preg_match('/^(\d{2}\/\d{2}\/\d{4})(?:\s*-?\s*\d{2}:\d{2}(?::\d{2})?)?\s+(.*)$/u', $line, $m)) {
                continue;
            }
            $date = $this->parseDate($m[1]);
            preg_match_all('/'.$money.'/u', $m[2], $vals, PREG_OFFSET_CAPTURE);
            $vals = $vals[0];
            $doc = preg_match('/^(\d{4,10})\b/', $m[2], $dm) ? $dm[1] : null;
            $desc = trim(preg_replace('/^\d{3,}\s*[—–\-]?\s*/u', '', count($vals) ? substr($m[2], 0, $vals[count($vals) >= 2 ? count($vals) - 2 : 0][1]) : $m[2]));
            $desc = trim(preg_replace('/\s*\*{2,}[\s\S]*$/u', '', $desc));
            $desc = trim(preg_replace('/[^\p{L}\d)]+$/u', '', $desc));
            if (self::isBalanceLine($desc)) {
                $ignored++;

                continue;
            }
            if ($date === null || count($vals) < 1) {
                $skipped++;

                continue;
            }
            $amtTok = count($vals) >= 2 ? $vals[count($vals) - 2][0] : $vals[0][0];
            $balTok = count($vals) >= 2 ? $vals[count($vals) - 1][0] : null;
            $amount = self::ocrMoney($amtTok);
            if ($amount === null) {
                $skipped++;

                continue;
            }
            $sign = str_ends_with(rtrim($amtTok), 'D') ? -1 : 1;
            $balance = $balTok !== null ? self::ocrMoney($balTok) * (str_ends_with(rtrim($balTok), 'D') ? -1 : 1) : null;
            $rows[] = ['date' => $date, 'description' => preg_replace('/\s{2,}/', ' ', $desc) ?: 'Sem descrição', 'amount' => round($sign * $amount, 2), 'balance' => $balance, 'doc' => $doc, '_line' => $i];
        }
        if ($rows === []) {
            throw new RuntimeException('O PDF é imagem e a leitura por OCR não encontrou lançamentos. Envie CSV/XLSX/OFX.');
        }
        // Extratos vêm do mais novo para o mais antigo: confere em ordem cronológica.
        if (count($rows) > 1 && $rows[0]['date'] > end($rows)['date']) {
            $rows = array_reverse($rows);
        }
        $warnings = ['PDF sem texto lido por OCR: confira datas e valores antes de aprovar.'];
        // Saldo corrido (anterior + lançamentos) x saldo lido em cada linha. Um saldo mal lido isolado não importa
        // se o corrido volta a bater depois; só avisa quando a diferença persiste até o fim.
        $run = $opening;
        $bad = [];
        foreach ($rows as &$r) {
            unset($r['_line']);
            if ($run === null) {
                continue;
            }
            $run = round($run + $r['amount'], 2);
            if ($r['balance'] !== null && abs($run - $r['balance']) > 0.009) {
                $bad[] = $r;
            }
        }
        unset($r);
        $last = end($rows);
        if ($run !== null && $last['balance'] !== null && abs($run - $last['balance']) > 0.009) {
            foreach ($bad as $b) {
                $warnings[] = 'Saldo não fecha em '.Carbon::parse($b['date'])->format('d/m').' ('.$b['description'].', '.number_format($b['amount'], 2, ',', '.').'). Confira o valor.';
            }
        }

        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'source' => 'PDF (OCR)', 'mapping' => [], 'skipped' => $skipped, 'warnings' => $warnings, 'opening_balance' => $opening, 'ignored_balance_lines' => $ignored];
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
            $final = preg_match('/\bfinal\s*(?:do\s*)?(?:cart[aã]o)?\s*:?\s*(\d{4})\b/iu', $line, $fm) ? $fm[1] : null;
            $rows[] = ['date' => $date, 'description' => preg_replace('/\s{2,}/', ' ', trim($m[2])), 'amount' => round($amount, 2), 'balance' => isset($m[4]) && $m[4] !== '' ? $this->parseMoney($m[4]) : null, 'final' => $final];
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

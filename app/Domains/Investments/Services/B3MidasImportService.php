<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Importação do export do Midas (B3 Área do Investidor): posicao.csv (snapshot agregado por ticker)
 * e proventos.csv (recebidos, só valor líquido). Prévia não grava nada; confirmar grava de forma idempotente
 * (chave de origem única por linha). Nunca cria lançamentos no Financeiro e nunca inventa PM.
 */
class B3MidasImportService
{
    public const POSITION_HEADER = ['ticker', 'quantidade', 'preco_medio', 'cotacao_30out', 'valor_mercado', 'distancia_medio_pct', 'preco_medio_status', 'origem_b3', 'instituicoes', 'produto'];

    public const INCOME_HEADER = ['data', 'ticker', 'tipo', 'quantidade', 'valor_bruto', 'valor_liquido', 'eh_reembolso_aluguel', 'classe', 'corretora', 'fonte_arquivo'];

    public const ORIGINS = ['custodia', 'custodia_fii', 'custodia_bdr', 'emprestimo_doador', 'custodia+emprestimo_doador'];

    public const INCOME_TYPES = ['Dividendo' => 'dividend', 'JCP' => 'jcp', 'Rendimento' => 'fii_income', 'Reembolso' => 'lending_reimbursement'];

    /** Lê CSV (UTF-8, com ou sem BOM, separador vírgula) em linhas associativas. */
    public function readCsv(string $content, array $required): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $header = array_map('trim', str_getcsv(array_shift($lines) ?? '', ',', '"', '\\'));
        $missing = array_values(array_diff($required, $header));
        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $rows[] = ['line' => $i + 2, 'data' => count($values) === count($header) ? array_combine($header, array_map('trim', $values)) : null, 'raw' => $line];
        }

        return ['header' => $header, 'missing' => $missing, 'rows' => $rows];
    }

    private function num(?string $v): ?float
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        return is_numeric($v) ? (float) $v : throw new \InvalidArgumentException("número inválido: {$v}");
    }

    public function parsePositions(string $content, string $baseDate): array
    {
        $csv = $this->readCsv($content, self::POSITION_HEADER);
        $rows = $errors = $warnings = [];
        if ($csv['missing']) {
            return ['rows' => [], 'errors' => ['Colunas ausentes em posicao.csv: '.implode(', ', $csv['missing'])], 'warnings' => []];
        }
        $seen = [];
        foreach ($csv['rows'] as $r) {
            $d = $r['data'];
            if (! $d) {
                $errors[] = "posicao.csv linha {$r['line']}: número de colunas diferente do cabeçalho.";
                continue;
            }
            try {
                $ticker = strtoupper($d['ticker']);
                if (! preg_match('/^[A-Z0-9]{4,12}$/', $ticker)) {
                    throw new \InvalidArgumentException("ticker inválido: {$d['ticker']}");
                }
                if (isset($seen[$ticker])) {
                    throw new \InvalidArgumentException("ticker repetido: {$ticker} (a posição deve vir agregada)");
                }
                $seen[$ticker] = true;
                $qty = $this->num($d['quantidade']);
                $mv = $this->num($d['valor_mercado']);
                if ($qty === null || $qty <= 0 || $mv === null) {
                    throw new \InvalidArgumentException('quantidade/valor de mercado obrigatórios');
                }
                if (! in_array($d['origem_b3'], self::ORIGINS, true)) {
                    throw new \InvalidArgumentException("origem_b3 desconhecida: {$d['origem_b3']}");
                }
                $pm = $this->num($d['preco_medio']);
                if ($pm !== null && $pm <= 0) {
                    $pm = null; // PM zero/negativo é tratado como desconhecido, nunca como zero.
                }
                [$custody, $lent] = match ($d['origem_b3']) {
                    'emprestimo_doador' => [0.0, $qty],
                    'custodia+emprestimo_doador' => [isset($d['quantidade_custodia']) && $d['quantidade_custodia'] !== '' ? (float) $d['quantidade_custodia'] : null, isset($d['quantidade_emprestada']) && $d['quantidade_emprestada'] !== '' ? (float) $d['quantidade_emprestada'] : null],
                    default => [$qty, 0.0],
                };
                if ($d['origem_b3'] === 'custodia+emprestimo_doador' && ($custody === null || $lent === null)) {
                    $warnings[] = "{$ticker}: {$qty} ações em custódia + emprestadas (doador); o arquivo não separa as quantidades. Ficam sem divisão até a corretora/Midas informar.";
                }
                if ($pm === null) {
                    $warnings[] = "{$ticker}: preço médio ".($d['preco_medio_status'] === 'desconhecido_confirmado_pelo_usuario' ? 'desconhecido (confirmado pelo Cassio)' : 'não informado').'; fica ausente (não zero).';
                }
                $close = $this->num($d['cotacao_30out']);
                if ($close !== null && abs(round($qty * $close, 2) - $mv) > 0.05) {
                    $warnings[] = "{$ticker}: quantidade × cotação (".number_format($qty * $close, 2, ',', '.').") difere do valor de mercado informado.";
                }
                $rows[] = [
                    'ticker' => $ticker, 'product' => $d['produto'] ?: null, 'origin' => $d['origem_b3'],
                    'quantity' => $qty, 'quantity_custody' => $custody, 'quantity_lent' => $lent,
                    'average_price' => $pm, 'average_price_status' => $d['preco_medio_status'] ?: null,
                    'close_price' => $close, 'market_value' => round($mv, 2), 'institutions' => $d['instituicoes'] ?: null,
                    'base_date' => $baseDate, 'source_key' => "b3pos:{$baseDate}:{$ticker}",
                ];
            } catch (\InvalidArgumentException $e) {
                $errors[] = "posicao.csv linha {$r['line']}: ".$e->getMessage();
            }
        }

        return compact('rows', 'errors', 'warnings');
    }

    public function parseIncome(string $content): array
    {
        $csv = $this->readCsv($content, self::INCOME_HEADER);
        $rows = $errors = $warnings = [];
        if ($csv['missing']) {
            return ['rows' => [], 'errors' => ['Colunas ausentes em proventos.csv: '.implode(', ', $csv['missing'])], 'warnings' => []];
        }
        $occurrence = [];
        foreach ($csv['rows'] as $r) {
            $d = $r['data'];
            if (! $d) {
                $errors[] = "proventos.csv linha {$r['line']}: número de colunas diferente do cabeçalho.";
                continue;
            }
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $d['data']);
                if (! $date || $date->format('Y-m-d') !== $d['data']) {
                    throw new \InvalidArgumentException("data inválida: {$d['data']}");
                }
                $ticker = strtoupper($d['ticker']);
                if (! preg_match('/^[A-Z0-9]{4,12}$/', $ticker)) {
                    throw new \InvalidArgumentException("ticker inválido: {$d['ticker']}");
                }
                $type = self::INCOME_TYPES[$d['tipo']] ?? throw new \InvalidArgumentException("tipo desconhecido: {$d['tipo']}");
                $lending = strtolower($d['eh_reembolso_aluguel']) === 'sim';
                if ($lending !== ($type === 'lending_reimbursement')) {
                    throw new \InvalidArgumentException('eh_reembolso_aluguel incoerente com o tipo');
                }
                $net = $this->num($d['valor_liquido']);
                if ($net === null || $net <= 0) {
                    throw new \InvalidArgumentException('valor_liquido obrigatório e positivo');
                }
                $qty = $this->num($d['quantidade']);
                $gross = $this->num($d['valor_bruto']);
                // Chave natural sem o nome do arquivo (reorganizar arquivos não duplica). Linhas idênticas legítimas
                // (dois créditos iguais no mesmo dia/corretora) recebem ordinal estável.
                $natural = implode('|', [$d['data'], $ticker, $d['tipo'], $qty === null ? '' : rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.'), number_format($net, 2, '.', ''), mb_strtolower($d['corretora'])]);
                $n = $occurrence[$natural] = ($occurrence[$natural] ?? 0) + 1;
                $rows[] = [
                    'ticker' => $ticker, 'payment_date' => $d['data'], 'type' => $type, 'source_type_label' => $d['tipo'],
                    'quantity' => $qty, 'gross_amount' => $gross, 'withholding_tax' => null, 'net_amount' => round($net, 2),
                    'is_lending' => $lending, 'broker_name' => $d['corretora'] ?: null, 'asset_class_label' => $d['classe'] ?: null,
                    'source_file' => $d['fonte_arquivo'] ?: null, 'source_key' => 'b3inc:'.sha1($natural).':'.$n,
                ];
            } catch (\InvalidArgumentException $e) {
                $errors[] = "proventos.csv linha {$r['line']}: ".$e->getMessage();
            }
        }
        $repeated = count(array_filter($occurrence, fn ($c) => $c > 1));
        if ($repeated) {
            $warnings[] = "{$repeated} grupo(s) de linhas idênticas em proventos.csv (mesma data, ticker, tipo, quantidade, valor e corretora). Mantidos como eventos distintos, numerados na ordem do arquivo.";
        }

        return compact('rows', 'errors', 'warnings');
    }

    /** Prévia: valida, soma e compara com o que já existe. Não grava nada. */
    public function preview(?string $positionCsv, ?string $incomeCsv, ?string $baseDate, ?array $meta = null): array
    {
        $errors = $warnings = [];
        $pos = ['rows' => [], 'errors' => [], 'warnings' => []];
        if ($positionCsv !== null) {
            $okDate = is_string($baseDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate) && Carbon::createFromFormat('!Y-m-d', $baseDate)->format('Y-m-d') === $baseDate;
            if (! $okDate) {
                $errors[] = 'Informe a data-base da posição B3 (AAAA-MM-DD).';
            } else {
                $pos = $this->parsePositions($positionCsv, $baseDate);
            }
        }
        $inc = $incomeCsv !== null ? $this->parseIncome($incomeCsv) : ['rows' => [], 'errors' => [], 'warnings' => []];
        $errors = array_merge($errors, $pos['errors'], $inc['errors']);
        $warnings = array_merge($pos['warnings'], $inc['warnings']);

        $posKeys = array_column($pos['rows'], 'source_key');
        $incKeys = array_column($inc['rows'], 'source_key');
        $existingPos = $posKeys ? B3PositionSnapshot::whereIn('source_key', $posKeys)->count() : 0;
        $existingInc = 0;
        foreach (array_chunk($incKeys, 500) as $chunk) {
            $existingInc += AssetIncomeEvent::whereIn('source_key', $chunk)->count();
        }

        $byOrigin = [];
        foreach ($pos['rows'] as $r) {
            $byOrigin[$r['origin']] = ['count' => ($byOrigin[$r['origin']]['count'] ?? 0) + 1, 'value' => round(($byOrigin[$r['origin']]['value'] ?? 0) + $r['market_value'], 2)];
        }
        $byType = $byBroker = $byYear = [];
        foreach ($inc['rows'] as $r) {
            $byType[$r['type']] = ['count' => ($byType[$r['type']]['count'] ?? 0) + 1, 'net' => round(($byType[$r['type']]['net'] ?? 0) + $r['net_amount'], 2)];
            $b = $r['broker_name'] ?? '—';
            $byBroker[$b] = round(($byBroker[$b] ?? 0) + $r['net_amount'], 2);
            $y = substr($r['payment_date'], 0, 4);
            $byYear[$y] = round(($byYear[$y] ?? 0) + $r['net_amount'], 2);
        }
        ksort($byYear);
        $dates = array_column($inc['rows'], 'payment_date');

        $summary = [
            'base_date' => $baseDate,
            'positions' => [
                'count' => count($pos['rows']), 'new' => count($pos['rows']) - $existingPos, 'existing' => $existingPos,
                'market_value' => round(array_sum(array_column($pos['rows'], 'market_value')), 2),
                'by_origin' => $byOrigin,
                'unknown_average_price' => array_values(array_map(fn ($r) => $r['ticker'], array_filter($pos['rows'], fn ($r) => $r['average_price'] === null))),
                'lent_tickers' => array_values(array_map(fn ($r) => $r['ticker'], array_filter($pos['rows'], fn ($r) => str_contains($r['origin'], 'emprestimo')))),
            ],
            'income' => [
                'count' => count($inc['rows']), 'new' => count($inc['rows']) - $existingInc, 'existing' => $existingInc,
                'net_total' => round(array_sum(array_column($inc['rows'], 'net_amount')), 2),
                'reimbursements' => count(array_filter($inc['rows'], fn ($r) => $r['is_lending'])),
                'from' => $dates ? min($dates) : null, 'to' => $dates ? max($dates) : null,
                'by_type' => $byType, 'by_broker' => $byBroker, 'by_year' => $byYear,
                'gross_informed' => count(array_filter($inc['rows'], fn ($r) => $r['gross_amount'] !== null)),
            ],
        ];

        if ($meta) {
            $checks = [
                ['posicao_rows', $summary['positions']['count']], ['posicao_valor_total', $summary['positions']['market_value']],
                ['proventos_rows', $summary['income']['count']], ['proventos_sum_liquido', $summary['income']['net_total']],
                ['proventos_reembolsos', $summary['income']['reimbursements']], ['posicao_data_base', $baseDate],
            ];
            foreach ($checks as [$key, $value]) {
                if (array_key_exists($key, $meta) && (is_numeric($meta[$key]) ? abs((float) $meta[$key] - (float) $value) > 0.009 : (string) $meta[$key] !== (string) $value)) {
                    $errors[] = "Conferência com meta.json falhou em {$key}: arquivo diz {$meta[$key]}, leitura deu {$value}.";
                }
            }
            $summary['meta_checked'] = true;
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => array_values(array_unique($warnings)), 'summary' => $summary, 'positions' => $pos['rows'], 'income' => $inc['rows']];
    }

    /** Grava a importação já conferida. Idempotente: linhas com chave existente são ignoradas. Não toca no Financeiro. */
    public function confirm(array $preview, InvestmentImportBatch $batch, int $userId): array
    {
        if (! $preview['ok']) {
            throw new \RuntimeException('A prévia tem erros; corrija antes de confirmar.');
        }
        if ($batch->status === 'confirmed') {
            throw new \RuntimeException('Esta importação já foi confirmada.');
        }

        return DB::transaction(function () use ($preview, $batch, $userId) {
            $assetIds = [];
            $classFor = function (string $label, string $origin) {
                $slug = match (true) {
                    $label === 'FII' || $origin === 'custodia_fii' => 'fiis',
                    $label === 'BDR' || $origin === 'custodia_bdr' => 'bdrs',
                    default => 'acoes',
                };

                return AssetClass::firstOrCreate(['slug' => $slug], ['name' => ['fiis' => 'FIIs', 'bdrs' => 'BDRs', 'acoes' => 'Ações'][$slug]])->id;
            };
            $assetFor = function (string $ticker, ?string $product, string $classLabel, string $origin) use (&$assetIds, $classFor) {
                if (isset($assetIds[$ticker])) {
                    return $assetIds[$ticker];
                }
                $asset = Asset::withTrashed()->where('ticker', $ticker)->first();
                if (! $asset) {
                    $name = $product ? trim(preg_replace('/^'.preg_quote($ticker, '/').'\s*-\s*/', '', $product)) : $ticker;
                    $asset = Asset::create(['ticker' => $ticker, 'name' => $name ?: $ticker, 'asset_class_id' => $classFor($classLabel, $origin), 'status' => true]);
                } elseif ($asset->trashed()) {
                    $asset->restore(); // ticker é único: reaproveita o cadastro (operações/proventos excluídos continuam excluídos)
                }

                return $assetIds[$ticker] = $asset->id;
            };

            $createdPos = $createdInc = 0;
            $now = now();
            foreach ($preview['positions'] as $r) {
                if (B3PositionSnapshot::where('source_key', $r['source_key'])->exists()) {
                    continue;
                }
                $label = $r['origin'] === 'custodia_fii' ? 'FII' : ($r['origin'] === 'custodia_bdr' ? 'BDR' : 'Ação');
                B3PositionSnapshot::create($r + ['import_batch_id' => $batch->id, 'asset_id' => $assetFor($r['ticker'], $r['product'], $label, $r['origin'])]);
                $createdPos++;
            }
            $existing = [];
            foreach (array_chunk(array_column($preview['income'], 'source_key'), 500) as $chunk) {
                $existing += array_flip(AssetIncomeEvent::whereIn('source_key', $chunk)->pluck('source_key')->all());
            }
            $insert = [];
            foreach ($preview['income'] as $r) {
                if (isset($existing[$r['source_key']])) {
                    continue;
                }
                $insert[] = $r + ['asset_id' => $assetFor($r['ticker'], null, (string) $r['asset_class_label'], ''), 'import_batch_id' => $batch->id, 'source' => 'b3_midas', 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($insert, 300) as $chunk) {
                AssetIncomeEvent::insert(array_map(fn ($r) => array_merge(['record_date' => null, 'lending_contract_ref' => null, 'lending_fee_rate' => null, 'bank_account_id' => null, 'transaction_id' => null, 'notes' => null], $r), $chunk));
                $createdInc += count($chunk);
            }

            $batch->update([
                'status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => $now,
                'base_date' => $preview['summary']['base_date'] ?? $batch->base_date,
                'summary' => $preview['summary'] + ['created' => ['positions' => $createdPos, 'income' => $createdInc]],
            ]);

            return ['positions' => $createdPos, 'income' => $createdInc];
        });
    }
}

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
    /** A coluna de cotação muda de nome a cada data-base (cotacao_30out, cotacao_06nov...): aceita qualquer cotacao_*. */
    public const POSITION_HEADER = ['ticker', 'quantidade', 'preco_medio', 'valor_mercado', 'distancia_medio_pct', 'preco_medio_status', 'origem_b3', 'instituicoes', 'produto'];

    public const MOVEMENT_HEADER = ['entrada_saida', 'data', 'movimentacao', 'ticker', 'produto', 'instituicao', 'quantidade', 'preco_unitario', 'valor_operacao'];

    /** Instituição (movimentação B3) => nome de corretora usado nos proventos. */
    public const BROKER_NAMES = ['BANCO BTG PACTUAL' => 'BTG Pactual', 'XP INVESTIMENTOS' => 'XP', 'ITAU CORRETORA' => 'Itaú', 'BB BANCO DE INVESTIMENTO' => 'BB Investimentos'];

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
        $quoteCol = collect($csv['header'])->first(fn ($h) => str_starts_with($h, 'cotacao_'));
        if (! $quoteCol) {
            return ['rows' => [], 'errors' => ['Coluna de cotação (cotacao_*) ausente em posicao.csv'], 'warnings' => []];
        }
        // Preço médio já conhecido no sistema (snapshot anterior) prevalece: nunca some nem é trocado sem aviso.
        $previous = [];
        $prevBatch = InvestmentImportBatch::where('status', 'confirmed')->whereNotNull('base_date')->whereDate('base_date', '<', $baseDate)->whereHas('positions')->orderByDesc('base_date')->orderByDesc('id')->first();
        if ($prevBatch) {
            foreach (B3PositionSnapshot::where('import_batch_id', $prevBatch->id)->whereNotNull('average_price')->get() as $p) {
                $previous[$p->ticker] = ['pm' => (float) $p->average_price, 'status' => $p->average_price_status];
            }
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
                $pmStatus = $d['preco_medio_status'] ?: null;
                if (isset($previous[$ticker]) && ($pm === null || abs($pm - $previous[$ticker]['pm']) > 0.00005)) {
                    $warnings[] = "{$ticker}: preço médio do arquivo (".($pm === null ? 'vazio' : number_format($pm, 4, ',', '.')).') difere do já registrado ('.number_format($previous[$ticker]['pm'], 4, ',', '.').'); mantido o registrado.';
                    [$pm, $pmStatus] = [$previous[$ticker]['pm'], $previous[$ticker]['status']];
                }
                if ($pm === null) {
                    $warnings[] = "{$ticker}: preço médio ".($d['preco_medio_status'] === 'desconhecido_confirmado_pelo_usuario' ? 'desconhecido (confirmado pelo Cassio)' : 'não informado').'; fica ausente (não zero).';
                }
                $close = $this->num($d[$quoteCol]);
                if ($close !== null && abs(round($qty * $close, 2) - $mv) > 0.05) {
                    $warnings[] = "{$ticker}: quantidade × cotação (".number_format($qty * $close, 2, ',', '.').") difere do valor de mercado informado.";
                }
                $rows[] = [
                    'ticker' => $ticker, 'product' => $d['produto'] ?: null, 'origin' => $d['origem_b3'],
                    'quantity' => $qty, 'quantity_custody' => $custody, 'quantity_lent' => $lent,
                    'average_price' => $pm, 'average_price_status' => $pmStatus,
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

    private function brokerName(string $institution): ?string
    {
        foreach (self::BROKER_NAMES as $needle => $name) {
            if (str_contains(mb_strtoupper($institution), $needle)) {
                return $name;
            }
        }

        return $institution !== '' ? $institution : null;
    }

    /**
     * movimentacao.csv: só as taxas de aluguel recebidas (crédito "Empréstimo" com valor) têm lugar no sistema
     * (provento tipo lending_fee). O resto (compras/vendas já refletidas na posição, transferências, contratos de
     * empréstimo, renda fixa, direitos) é só contado e reportado: operações gerariam lançamentos no Financeiro.
     */
    public function parseMovements(string $content): array
    {
        $csv = $this->readCsv($content, self::MOVEMENT_HEADER);
        if ($csv['missing']) {
            return ['rows' => [], 'skipped' => [], 'count' => 0, 'errors' => ['Colunas ausentes em movimentacao.csv: '.implode(', ', $csv['missing'])]];
        }
        $rows = $errors = $skipped = [];
        $occurrence = [];
        foreach ($csv['rows'] as $r) {
            $d = $r['data'];
            if (! $d) {
                $errors[] = "movimentacao.csv linha {$r['line']}: número de colunas diferente do cabeçalho.";
                continue;
            }
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $d['data']);
                if (! $date || $date->format('Y-m-d') !== $d['data']) {
                    throw new \InvalidArgumentException("data inválida: {$d['data']}");
                }
                $value = $this->num($d['valor_operacao']);
                $qty = $this->num($d['quantidade']);
                $mov = trim($d['movimentacao']);
                $credit = strcasecmp($d['entrada_saida'], 'Credito') === 0 || strcasecmp($d['entrada_saida'], 'Crédito') === 0;
                if ($mov === 'Empréstimo' && $credit && $value !== null && $value > 0) {
                    $ticker = strtoupper($d['ticker']);
                    $broker = $this->brokerName($d['instituicao']);
                    $natural = implode('|', [$d['data'], $ticker, 'lending_fee', $qty ? rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.') : '', number_format($value, 2, '.', ''), mb_strtolower((string) $broker)]);
                    $n = $occurrence[$natural] = ($occurrence[$natural] ?? 0) + 1;
                    $rows[] = [
                        'ticker' => $ticker, 'payment_date' => $d['data'], 'type' => 'lending_fee', 'source_type_label' => 'Empréstimo (crédito B3)',
                        'quantity' => $qty ?: null, 'gross_amount' => null, 'withholding_tax' => null, 'net_amount' => round($value, 2),
                        'is_lending' => true, 'broker_name' => $broker, 'asset_class_label' => null,
                        'source_file' => 'movimentacao.csv', 'source_key' => 'b3mov:'.sha1($natural).':'.$n,
                    ];
                    continue;
                }
                $reason = match (true) {
                    in_array($mov, ['Juros Sobre Capital Próprio', 'Dividendo', 'Rendimento'], true) => 'provento (já vem em proventos.csv)',
                    $mov === 'Transferência - Liquidação' && $value === null => 'liquidação sem valor (movimento de empréstimo/custódia)',
                    $mov === 'Transferência - Liquidação' => $credit ? 'compra liquidada (já refletida na posição; operação geraria lançamento no Financeiro)' : 'venda liquidada (já refletida na posição; operação geraria lançamento no Financeiro)',
                    $mov === 'Empréstimo' && $value !== null && $value > 0 => 'débito de empréstimo com valor (recall/liquidação; natureza do valor não identificada)',
                    $mov === 'Empréstimo' => 'contrato de empréstimo (quantidade, sem valor)',
                    $mov === 'Transferência' => 'transferência de custódia',
                    str_starts_with(mb_strtoupper($mov), 'RESGATE') || mb_strtoupper($mov) === 'APLICAÇÃO' => 'renda fixa (CDB)',
                    default => 'outros ('.$mov.')',
                };
                $skipped[$reason] = ['count' => ($skipped[$reason]['count'] ?? 0) + 1, 'value' => round(($skipped[$reason]['value'] ?? 0) + (float) $value, 2)];
            } catch (\InvalidArgumentException $e) {
                $errors[] = "movimentacao.csv linha {$r['line']}: ".$e->getMessage();
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'count' => count($csv['rows']), 'errors' => $errors];
    }

    /** Evento equivalente já registrado por outra origem (ex.: extrato da corretora). */
    private function equivalentOtherSource(array $r): ?AssetIncomeEvent
    {
        return AssetIncomeEvent::where('source', '!=', 'b3_midas')->where('source', '!=', 'b3_movimentacao')
            ->where('ticker', $r['ticker'])->where('type', $r['type'])->whereDate('payment_date', $r['payment_date'])
            ->where('net_amount', $r['net_amount'])->first();
    }

    /**
     * Correção de data: evento B3 já gravado cuja chave sumiu do arquivo novo e que casa de forma única
     * (ticker, tipo, quantidade, valor, corretora) com uma linha nova dentro da janela do arquivo. Move o evento
     * em vez de duplicar; se outra origem já registra o pagamento na data nova, o evento antigo é removido.
     */
    private function dateCorrections(array $incomeRows): array
    {
        if (! $incomeRows) {
            return [];
        }
        $dates = array_column($incomeRows, 'payment_date');
        [$from, $to] = [min($dates), max($dates)];
        $fileKeys = array_flip(array_column($incomeRows, 'source_key'));
        $sig = fn ($ticker, $type, $qty, $net, $broker) => implode('|', [$ticker, $type, $qty === null ? '' : rtrim(rtrim(number_format((float) $qty, 6, '.', ''), '0'), '.'), number_format((float) $net, 2, '.', ''), mb_strtolower((string) $broker)]);
        $orphans = [];
        AssetIncomeEvent::where('source', 'b3_midas')->whereBetween('payment_date', [$from, $to])->orderBy('id')
            ->each(function ($e) use (&$orphans, $fileKeys, $sig) {
                if (! isset($fileKeys[$e->source_key])) {
                    $orphans[$sig($e->ticker, $e->type, $e->quantity, $e->net_amount, $e->broker_name)][] = $e;
                }
            });
        if (! $orphans) {
            return [];
        }
        $existing = [];
        foreach (array_chunk(array_keys($fileKeys), 500) as $chunk) {
            $existing += array_flip(AssetIncomeEvent::whereIn('source_key', $chunk)->pluck('source_key')->all());
        }
        $new = [];
        foreach ($incomeRows as $r) {
            if (! isset($existing[$r['source_key']])) {
                $new[$sig($r['ticker'], $r['type'], $r['quantity'], $r['net_amount'], $r['broker_name'])][] = $r;
            }
        }
        $out = [];
        foreach ($orphans as $k => $events) {
            if (count($events) === 1 && count($new[$k] ?? []) === 1) {
                $r = $new[$k][0];
                $other = $this->equivalentOtherSource($r);
                $out[] = ['event_id' => $events[0]->id, 'ticker' => $r['ticker'], 'type' => $r['type'], 'net_amount' => $r['net_amount'], 'from' => $events[0]->payment_date->toDateString(), 'to' => $r['payment_date'], 'source_key' => $r['source_key'], 'source_file' => $r['source_file'], 'action' => $other ? 'remove_already_in_other_source' : 'move', 'other_event_id' => $other?->id];
            }
        }

        return $out;
    }

    /** Prévia: valida, soma e compara com o que já existe. Não grava nada. */
    public function preview(?string $positionCsv, ?string $incomeCsv, ?string $baseDate, ?array $meta = null, ?string $movementCsv = null): array
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
        $mov = $movementCsv !== null ? $this->parseMovements($movementCsv) : ['rows' => [], 'skipped' => [], 'count' => 0, 'errors' => []];
        $errors = array_merge($errors, $pos['errors'], $inc['errors'], $mov['errors']);
        $corrections = $inc['errors'] ? [] : $this->dateCorrections($inc['rows']);
        $correctedKeys = array_flip(array_column($corrections, 'source_key'));
        foreach ($corrections as $c) {
            $warnings[] = "{$c['ticker']} {$c['type']} R$ ".number_format($c['net_amount'], 2, ',', '.').": data de pagamento corrigida de {$c['from']} para {$c['to']} sem duplicar".($c['action'] === 'move' ? '.' : " (o pagamento já está registrado pelo extrato da corretora, evento #{$c['other_event_id']}; o evento B3 antigo sai).");
        }
        // Linhas novas (inclusive taxas de aluguel) que outra origem já registrou não são gravadas de novo.
        $covered = [];
        foreach (array_merge($inc['rows'], $mov['rows']) as $r) {
            if (! AssetIncomeEvent::where('source_key', $r['source_key'])->exists() && ($o = $this->equivalentOtherSource($r))) {
                $covered[$r['source_key']] = $o->id;
            }
        }
        $warnings = array_merge($warnings, $pos['warnings'], $inc['warnings']);

        $posKeys = array_column($pos['rows'], 'source_key');
        $incKeys = array_column($inc['rows'], 'source_key');
        $existingPos = $posKeys ? B3PositionSnapshot::whereIn('source_key', $posKeys)->count() : 0;
        $existingInc = 0;
        foreach (array_chunk($incKeys, 500) as $chunk) {
            $existingInc += AssetIncomeEvent::whereIn('source_key', $chunk)->count();
        }
        $movKeys = array_column($mov['rows'], 'source_key');
        $existingMov = $movKeys ? AssetIncomeEvent::whereIn('source_key', $movKeys)->count() : 0;

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
                'date_corrections' => $corrections,
                'covered_by_other_source' => array_intersect_key($covered, array_flip($incKeys)),
            ],
        ];
        if ($movementCsv !== null) {
            $summary['movements'] = [
                'count' => $mov['count'], 'lending_fees' => count($mov['rows']), 'lending_fees_new' => count($mov['rows']) - $existingMov,
                'lending_fees_total' => round(array_sum(array_column($mov['rows'], 'net_amount')), 2),
                'covered_by_other_source' => array_intersect_key($covered, array_flip($movKeys)),
                'not_imported' => $mov['skipped'],
            ];
        }
        $summary['income']['new'] -= count(array_unique(array_merge(array_keys($correctedKeys), array_keys(array_intersect_key($covered, array_flip($incKeys))))));

        if ($meta) {
            $checks = [
                ['posicao_rows', $summary['positions']['count']], ['posicao_valor_total', $summary['positions']['market_value']],
                ['proventos_rows', $summary['income']['count']], ['proventos_sum_liquido', $summary['income']['net_total']],
                ['proventos_reembolsos', $summary['income']['reimbursements']], ['posicao_data_base', $baseDate],
            ];
            if ($movementCsv !== null) {
                $checks[] = ['movimentacao_rows', $mov['count']];
            }
            foreach ($checks as [$key, $value]) {
                if (array_key_exists($key, $meta) && (is_numeric($meta[$key]) ? abs((float) $meta[$key] - (float) $value) > 0.009 : (string) $meta[$key] !== (string) $value)) {
                    $errors[] = "Conferência com meta.json falhou em {$key}: arquivo diz {$meta[$key]}, leitura deu {$value}.";
                }
            }
            $summary['meta_checked'] = true;
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => array_values(array_unique($warnings)), 'summary' => $summary, 'positions' => $pos['rows'], 'income' => $inc['rows'], 'movements' => $mov['rows'], 'corrections' => $corrections, 'covered' => $covered];
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
            $moved = $removed = 0;
            foreach ($preview['corrections'] ?? [] as $c) {
                $event = AssetIncomeEvent::find($c['event_id']);
                if (! $event || $event->source !== 'b3_midas' || AssetIncomeEvent::where('source_key', $c['source_key'])->exists()) {
                    continue;
                }
                if ($c['action'] === 'move') {
                    $event->update(['payment_date' => $c['to'], 'source_key' => $c['source_key'], 'source_file' => $c['source_file'], 'import_batch_id' => $batch->id,
                        'notes' => trim(($event->notes ? $event->notes.' ' : '')."Data corrigida de {$c['from']} para {$c['to']} (B3, importação #{$batch->id}).")]);
                    $moved++;
                } else {
                    $event->delete();
                    $removed++;
                }
            }
            $covered = $preview['covered'] ?? [];
            $corrected = array_flip(array_column($preview['corrections'] ?? [], 'source_key'));
            $existing = [];
            foreach (array_chunk(array_column($preview['income'], 'source_key'), 500) as $chunk) {
                $existing += array_flip(AssetIncomeEvent::whereIn('source_key', $chunk)->pluck('source_key')->all());
            }
            $insert = [];
            foreach ($preview['income'] as $r) {
                if (isset($existing[$r['source_key']]) || isset($covered[$r['source_key']]) || isset($corrected[$r['source_key']])) {
                    continue;
                }
                $insert[] = $r + ['asset_id' => $assetFor($r['ticker'], null, (string) $r['asset_class_label'], ''), 'import_batch_id' => $batch->id, 'source' => 'b3_midas', 'created_at' => $now, 'updated_at' => $now];
            }
            $createdFees = 0;
            foreach ($preview['movements'] ?? [] as $r) {
                if (isset($covered[$r['source_key']]) || AssetIncomeEvent::where('source_key', $r['source_key'])->exists()) {
                    continue;
                }
                AssetIncomeEvent::create($r + ['asset_id' => $assetFor($r['ticker'], null, 'Ação', ''), 'import_batch_id' => $batch->id, 'source' => 'b3_movimentacao']);
                $createdFees++;
            }
            foreach (array_chunk($insert, 300) as $chunk) {
                AssetIncomeEvent::insert(array_map(fn ($r) => array_merge(['record_date' => null, 'lending_contract_ref' => null, 'lending_fee_rate' => null, 'bank_account_id' => null, 'transaction_id' => null, 'notes' => null], $r), $chunk));
                $createdInc += count($chunk);
            }

            $batch->update([
                'status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => $now,
                'base_date' => $preview['summary']['base_date'] ?? $batch->base_date,
                'summary' => $preview['summary'] + ['created' => ['positions' => $createdPos, 'income' => $createdInc, 'income_dates_corrected' => $moved, 'income_removed_duplicate' => $removed, 'lending_fees' => $createdFees]],
            ]);

            return ['positions' => $createdPos, 'income' => $createdInc];
        });
    }
}

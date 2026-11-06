<?php

namespace App\Domains\Investments\Services\Statements;

/**
 * Extrato "Conta Investimento" do BTG Pactual em PDF (texto via pdftotext -layout).
 * Layout calibrado com 1 arquivo real (01/01–06/11/2026). Débito/crédito é decidido pelo saldo corrido
 * (saldo anterior ± valor = saldo da linha), não pela posição da coluna, que varia entre páginas.
 */
class BtgStatementPdfParser
{
    private const NUM = '-?\d{1,3}(?:\.\d{3})*,\d{2}';

    public static function detect(string $text): bool
    {
        return str_contains($text, 'Movimentação - Conta Investimento') && (str_contains($text, 'BTGPACTUAL') || str_contains($text, 'BTG PACTUAL') || str_contains($text, 'Banco: 208'));
    }

    private static function num(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $v);
    }

    /** Classificação pela descrição. @return array{0:string,1:?string} [kind, ticker] */
    public static function classify(string $desc): array
    {
        $d = preg_replace('/\s+/', ' ', mb_strtoupper(trim($desc)));
        $ticker = null;
        if (preg_match('/(?:BTC|-)\s+([A-Z][A-Z0-9]{3}\d{1,2})\s*$/', $d, $m) || preg_match('/^SU-([A-Z][A-Z0-9]{3}\d{1,2})\b/', $d, $m)) {
            $ticker = $m[1];
        }
        $isProvento = preg_match('/^(DIVIDENDOS|JUROS S\/ CAPITAL|RENDIMENTOS|LEIL[ÃA]O DE FRA[ÇC][ÕO]ES)\b/u', $d);
        $kind = match (true) {
            str_starts_with($d, 'TAXA REMUNERAÇÃO - BTC') => 'lending_fee',
            str_starts_with($d, 'IR - BTC') => 'lending_tax',
            str_starts_with($d, 'TAXA EMOLUMENTOS') || str_contains($d, 'TAXA SOBRE VALOR EM CUSTÓDIA') || str_contains($d, 'CORRETAGEM BTC ALUGUEL') => 'fee',
            str_starts_with($d, 'LIQ BOLSA (ALUGUEL)') => 'lending_settlement',
            str_starts_with($d, 'LIQ BOLSA (OPERACOES)') => 'trade_settlement',
            str_starts_with($d, 'IRRF') => 'tax',
            str_starts_with($d, 'TED ') => 'transfer',
            str_contains($d, 'CONTA REMUNERADA') || str_contains($d, 'CUSTÓDIA REMUNERADA') || str_contains($d, 'SALDO REMUNERADO') => 'cash_yield',
            str_contains($d, 'PAGAMENTO DE FRAÇÃO') || str_starts_with($d, 'LEILÃO DE FRAÇÕES - À VISTA') => 'fraction',
            str_starts_with($d, 'SU-') => 'subscription_right',
            $isProvento && str_contains($d, '- ALUGUEL') => 'lending_reimbursement',
            str_starts_with($d, 'DIVIDENDOS') => 'dividend',
            str_starts_with($d, 'JUROS S/ CAPITAL') => 'jcp',
            str_starts_with($d, 'RENDIMENTOS') => $ticker && str_ends_with($ticker, '11') ? 'fii_income' : 'yield',
            default => 'unknown',
        };

        return [$kind, $ticker];
    }

    /**
     * @return array{rows: list<array>, reconciliation: array}
     */
    public function parse(string $text): array
    {
        $rx = '/^\s*(\d{2})\/(\d{2})\/(\d{4})\s+(.*?)\s+((?:'.self::NUM.'\s+)*'.self::NUM.')\s*$/u';
        $rows = [];
        $prev = null;
        $opening = null;
        $printedFinal = null;
        $mismatches = 0;
        $credits = 0.0;
        $debits = 0.0;
        $printedCredits = $printedDebits = null;
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/Total de Créditos\s+('.self::NUM.')/u', $line, $m)) {
                $printedCredits = self::num($m[1]);
            }
            if (preg_match('/Total de Débitos\s+('.self::NUM.')/u', $line, $m)) {
                $printedDebits = self::num($m[1]);
            }
            if (! preg_match($rx, $line, $m)) {
                continue;
            }
            $date = "$m[3]-$m[2]-$m[1]";
            $desc = trim(preg_replace('/\s+/', ' ', $m[4]));
            $nums = array_map([self::class, 'num'], preg_split('/\s+/', trim($m[5])));
            $balance = end($nums);
            if (str_starts_with($desc, 'Saldo Inicial')) {
                $opening = $prev = $balance;

                continue;
            }
            if (str_starts_with($desc, 'Saldo Final')) {
                $printedFinal = $balance;

                continue;
            }
            $msgs = [];
            if (count($nums) < 2 || $prev === null) {
                $amount = null;
                $msgs[] = 'Valor ou saldo anterior ausente: revisar no PDF.';
                $mismatches++;
            } else {
                $value = $nums[count($nums) - 2];
                $delta = round($balance - $prev, 2);
                if (abs(abs($delta) - $value) > 0.005) {
                    $msgs[] = 'Saldo não fecha (anterior '.number_format($prev, 2, ',', '.').' ± '.number_format($value, 2, ',', '.').' ≠ '.number_format($balance, 2, ',', '.').'): revisar.';
                    $mismatches++;
                    $amount = null;
                } else {
                    $amount = $delta;
                    $amount > 0 ? $credits += $amount : $debits += -$amount;
                }
            }
            $prev = $balance;
            [$kind, $ticker] = self::classify($desc);
            $rows[] = ['date' => $date, 'description' => $desc, 'kind' => $kind, 'ticker' => $ticker, 'amount' => $amount, 'balance' => $balance, 'messages' => $msgs];
        }
        $rows = $this->inferTickers($rows);
        $computed = $opening !== null ? round($opening + $credits - $debits, 2) : null;

        return [
            'rows' => $rows,
            'reconciliation' => [
                'opening' => $opening, 'credits' => round($credits, 2), 'debits' => round($debits, 2),
                'computed_final' => $computed, 'last_running_balance' => $prev, 'printed_final' => $printedFinal,
                'printed_credits' => $printedCredits, 'printed_debits' => $printedDebits, 'line_mismatches' => $mismatches,
                'ok' => $mismatches === 0 && $computed !== null && abs($computed - (float) $prev) < 0.005
                    && ($printedCredits === null || abs($printedCredits - $credits) < 0.005)
                    && ($printedDebits === null || abs($printedDebits - $debits) < 0.005),
                'printed_final_differs' => $printedFinal !== null && $prev !== null && abs($printedFinal - $prev) >= 0.005,
            ],
        ];
    }

    /** Chave do emissor: texto após "s/", sem ticker, segmento de listagem e espaços (ex.: "LOJAS RENNER ON NM" = "LOJAS RENNERON"). */
    public static function issuerKey(string $desc): ?string
    {
        // Emissor vem após "s/" minúsculo ("JUROS S/ CAPITAL" usa maiúsculo e não conta).
        if (! preg_match('/\ss\/\s*(.*)$/u', preg_replace('/\s+/', ' ', $desc), $m)) {
            return null;
        }
        $m[1] = mb_strtoupper($m[1]);
        $k = preg_replace('/\s*-\s*([A-Z][A-Z0-9]{3}\d{1,2})?\s*$/', '', $m[1]);
        $k = preg_replace('/\b(N1|N2|NM|MA|MB|EJ|ED|EX|EB|ATZ)\b/', '', $k);
        $k = preg_replace('/[^A-Z0-9]/', '', $k);

        return strlen($k) >= 3 ? $k : null;
    }

    /**
     * pdftotext corta descrições longas e o ticker some. Infere pelo nome do emissor em outra linha do MESMO extrato
     * (igual ou prefixo único); sem correspondência única, fica sem ticker (revisão).
     */
    private function inferTickers(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            if ($r['ticker'] && ($k = self::issuerKey($r['description']))) {
                $map[$k][$r['ticker']] = true;
            }
        }
        foreach ($rows as &$r) {
            if ($r['ticker'] || ! ($k = self::issuerKey($r['description']))) {
                continue;
            }
            $cands = [];
            foreach ($map as $key => $tickers) {
                if ($key === $k || str_starts_with($key, $k) || str_starts_with($k, $key)) {
                    $cands += $tickers;
                }
            }
            if (count($cands) === 1) {
                $r['ticker'] = array_key_first($cands);
                $r['messages'][] = 'Ticker inferido pelo nome do emissor em outra linha do extrato: conferir.';
            } else {
                $r['messages'][] = count($cands) ? 'Emissor ambíguo ('.implode(', ', array_keys($cands)).'): informe o ticker.' : 'Ticker ausente no PDF: informe o ticker.';
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Converte em linhas de staging: IR-BTC é vinculado à taxa de aluguel do mesmo dia/ticker (bruto − IR = líquido).
     * @return list<array>
     */
    public function toStagingRows(array $parsed): array
    {
        $rows = $parsed['rows'];
        $fees = [];
        foreach ($rows as $i => $r) {
            if ($r['kind'] === 'lending_fee' && $r['ticker']) {
                $fees[$r['date'].'|'.$r['ticker']][] = $i;
            }
        }
        $out = [];
        foreach ($rows as $i => $r) {
            $amount = $r['amount'];
            $n = [
                'date' => $r['date'], 'ticker' => $r['ticker'], 'kind' => $r['kind'], 'source_type_label' => mb_substr($r['description'], 0, 250),
                'quantity' => null, 'unit_price' => null, 'gross_amount' => null, 'withholding_tax' => null, 'fee_amount' => null,
                'net_amount' => $amount, 'lending_fee_rate' => null, 'external_id' => null, 'messages' => $r['messages'],
                'raw' => [$r['date'], $r['description'], $amount, $r['balance']], 'link_to' => null,
            ];
            if ($r['kind'] === 'lending_fee') {
                $n['gross_amount'] = $amount;
            }
            if ($r['kind'] === 'lending_tax') {
                $target = $fees[$r['date'].'|'.$r['ticker']][0] ?? null;
                $n['link_to'] = $target;
                $n['messages'][] = $target === null ? 'IR de aluguel sem taxa correspondente no mesmo dia: revisar.' : 'IR vinculado à taxa de aluguel do mesmo dia.';
            }
            if (in_array($r['kind'], ['dividend', 'jcp', 'fii_income', 'yield', 'lending_reimbursement', 'lending_fee'], true) && $amount !== null && $amount < 0) {
                $n['messages'][] = 'Provento com valor a débito: revisar (estorno?).';
            }
            if ($r['kind'] === 'unknown') {
                $n['messages'][] = 'Descrição não reconhecida no layout BTG.';
            }
            $out[$i] = $n;
        }
        foreach ($out as $i => $n) {
            if ($n['kind'] === 'lending_tax' && $n['link_to'] !== null) {
                $t = $n['link_to'];
                $out[$t]['withholding_tax'] = round(($out[$t]['withholding_tax'] ?? 0) + abs((float) $n['net_amount']), 2);
                $out[$t]['net_amount'] = round((float) $out[$t]['gross_amount'] - $out[$t]['withholding_tax'], 2);
            }
        }

        return array_values($out);
    }
}

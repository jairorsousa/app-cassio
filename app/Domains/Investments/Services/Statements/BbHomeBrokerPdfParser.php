<?php

namespace App\Domains\Investments\Services\Statements;

/**
 * "HOME BROKER BB - FINANCEIRO - MOVIMENTAÇÃO FINANCEIRA" (PDF, pdftotext -layout). Calibrado com 1 arquivo real
 * (só PAG.RENDIMENTOS de FII). Valor = crédito pago em conta (sem IR/taxa no relatório). O "Total dos lançamentos"
 * se repete em cada página com o total GERAL: serve para conferir a soma, não é somado.
 */
class BbHomeBrokerPdfParser
{
    public static function detect(string $text): bool
    {
        return str_contains($text, 'HOME BROKER BB') && str_contains($text, 'MOVIMENTAÇÃO FINANCEIRA');
    }

    private static function num(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $v);
    }

    public static function kind(string $event, ?string $ticker): string
    {
        $e = BrokerStatementParser::norm($event);

        return match (true) {
            str_contains($e, 'rendimento') => $ticker && str_ends_with($ticker, '11') ? 'fii_income' : 'yield',
            str_contains($e, 'dividendo') => 'dividend',
            str_contains($e, 'juros') || str_contains($e, 'jcp') => 'jcp',
            default => 'unknown',
        };
    }

    /** @return array{rows: list<array>, reconciliation: array} */
    public function parse(string $text): array
    {
        $rx = '/^\s*(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2})\.(\d{2})\.(\d{4})\s+(\S+)\s+(.+?)\s+-\s+TTLO\.:\s*([A-Z][A-Z0-9]{3}\d{1,2})\s+QTDE:\s*([\d.]+)\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})\s*$/u';
        $rows = [];
        $printed = [];
        $unparsed = 0;
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/Total dos lançamentos no período\*?\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})/u', $line, $m)) {
                $printed[] = self::num($m[1]);

                continue;
            }
            if (preg_match($rx, $line, $m)) {
                $event = trim(preg_replace('/\s+/', ' ', $m[8]));
                $rows[] = [
                    'date' => "$m[3]-$m[2]-$m[1]", 'event_date' => "$m[6]-$m[5]-$m[4]", 'custody' => $m[7], 'event' => $event,
                    'ticker' => $m[9], 'quantity' => (float) str_replace('.', '', $m[10]), 'amount' => self::num($m[11]),
                    'kind' => self::kind($event, $m[9]),
                ];
            } elseif (preg_match('/^\s*\d{2}\.\d{2}\.\d{4}\s/', $line)) {
                $unparsed++; // linha com data que não bateu no layout: revisar
            }
        }
        $sum = round(array_sum(array_column($rows, 'amount')), 2);
        $printedTotals = array_values(array_unique($printed));

        return ['rows' => array_reverse($rows), 'reconciliation' => [
            'rows' => count($rows), 'sum' => $sum, 'printed_total' => $printedTotals[0] ?? null,
            'printed_totals_distinct' => count($printedTotals), 'unparsed_dated_lines' => $unparsed,
            'from' => $rows ? end($rows)['date'] : null, 'to' => $rows[0]['date'] ?? null,
            'ok' => $unparsed === 0 && count($printedTotals) === 1 && abs($printedTotals[0] - $sum) < 0.005,
        ]];
    }

    /** @return list<array> linhas de staging (mesmo formato do parser BTG) */
    public function toStagingRows(array $parsed): array
    {
        return array_map(fn ($r) => [
            'date' => $r['date'], 'ticker' => $r['ticker'], 'kind' => $r['kind'], 'source_type_label' => $r['event'],
            'quantity' => $r['quantity'], 'unit_price' => null, 'gross_amount' => null, 'withholding_tax' => null, 'fee_amount' => null,
            'net_amount' => $r['amount'], 'lending_fee_rate' => null, 'external_id' => null,
            'messages' => array_values(array_filter([$r['event_date'] !== $r['date'] ? 'Data do evento '.$r['event_date'].' difere da data do lançamento.' : null, $r['kind'] === 'unknown' ? 'Evento não reconhecido no layout BB.' : null])),
            'raw' => [$r['date'], $r['event_date'], $r['custody'], $r['event'], $r['ticker'], $r['quantity'], $r['amount']], 'link_to' => null,
        ], $parsed['rows']);
    }
}

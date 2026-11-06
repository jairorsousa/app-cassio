<?php

namespace App\Domains\Investments\Services\Statements;

/**
 * Extrato de CONTA (corretora/banco) em CSV com saldo por linha: data, descrição, valor com sinal (ou débito/crédito), saldo.
 * Genérico por nome de coluna (layout XP NÃO validado com arquivo real). Ordem decrescente é invertida preservando a
 * ordem do mesmo dia; reconciliação: saldo anterior + valor = saldo da linha. Abertura derivada da 1ª linha (não inventada).
 * Nada aqui é provento B3 nem lançamento financeiro: tudo vira "outros movimentos" para revisão manual.
 */
class BankAccountCsvParser
{
    private const COLS = [
        'date' => ['data', 'data movimentacao', 'data lancamento', 'data mov', 'dt'],
        'description' => ['descricao', 'historico', 'lancamento', 'descricao exibida', 'movimentacao'],
        'amount' => ['valor', 'valor r$', 'valor (r$)', 'valor lancamento', 'montante'],
        'credit' => ['credito', 'entrada', 'creditos'],
        'debit' => ['debito', 'saida', 'debitos'],
        'balance' => ['saldo', 'saldo r$', 'saldo (r$)', 'saldo apos lancamento', 'saldo final'],
    ];

    /** @return array<string,int>|null índice de cada coluna; null se não for extrato de conta com saldo */
    public static function detect(array $headers): ?array
    {
        $idx = [];
        foreach (self::COLS as $f => $syn) {
            foreach ($headers as $i => $h) {
                if (! isset($idx[$f]) && in_array(BrokerStatementParser::norm($h), $syn, true)) {
                    $idx[$f] = $i;
                }
            }
        }
        $hasValue = isset($idx['amount']) || (isset($idx['credit']) && isset($idx['debit']));

        return isset($idx['date'], $idx['description'], $idx['balance']) && $hasValue ? $idx : null;
    }

    public static function classify(string $desc, float $amount): string
    {
        $d = BrokerStatementParser::norm($desc);
        $has = fn (array $w) => (bool) array_filter($w, fn ($x) => str_contains($d, $x));

        return match (true) {
            $has(['devolvido', 'devolucao', 'estorno', 'devolv']) => 'transfer_return',
            $has(['rendimento automatico', 'remuneracao', 'saldo remunerado', 'conta remunerada', 'rendimento conta']) => 'cash_yield',
            $has(['financiamento', 'financ ', 'parcela', 'emprestimo', 'consorcio', 'bmw', 'volkswagen', ' vw ', 'banco vw', 'bradesco financ']) => 'loan_payment',
            $has(['dividendo', 'juros sobre capital', 'jcp']) => 'dividend_hint',
            $has(['operacoes', 'liquidacao bolsa', 'liq bolsa', 'compra de', 'venda de']) => 'trade_settlement',
            $has(['tarifa', 'taxa', 'iof', 'custodia']) => 'fee',
            $has(['ted', 'pix', 'doc ', 'transferencia', 'transf', 'deposito', 'resgate', 'aplicacao']) => 'transfer',
            default => 'other',
        };
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{rows: list<array>, reconciliation: array}
     */
    public function parse(array $headers, array $rows, string $fileTag = ''): array
    {
        $idx = self::detect($headers) ?? throw new \RuntimeException('CSV sem colunas de data, descrição, valor e saldo.');
        $items = [];
        foreach ($rows as $i => $r) {
            $date = BrokerStatementParser::date($r[$idx['date']] ?? '');
            $desc = trim(preg_replace('/\s+/', ' ', (string) ($r[$idx['description']] ?? '')));
            if (isset($idx['amount'])) {
                $amount = BrokerStatementParser::number($r[$idx['amount']] ?? '');
            } else {
                $c = BrokerStatementParser::number($r[$idx['credit']] ?? '');
                $dbt = BrokerStatementParser::number($r[$idx['debit']] ?? '');
                $amount = ($c === null && $dbt === null) ? null : round(abs((float) $c) - abs((float) $dbt), 2);
            }
            $balance = BrokerStatementParser::number($r[$idx['balance']] ?? '');
            if ($date === null && $desc === '' && $amount === null) {
                continue;
            }
            $items[] = ['file_row' => $i + 1, 'date' => $date, 'description' => $desc, 'amount' => $amount === null ? null : round($amount, 2), 'balance' => $balance === null ? null : round($balance, 2)];
        }
        // Ordem: se a 1ª data é posterior à última, o arquivo está decrescente → inverte tudo (mantém a ordem exata do mesmo dia invertida).
        $dates = array_values(array_filter(array_column($items, 'date')));
        $reversed = count($dates) > 1 && $dates[0] > end($dates);
        if ($reversed) {
            $items = array_reverse($items);
        }
        $prev = null;
        $opening = null;
        $mismatches = [];
        $credits = $debits = 0.0;
        $seen = [];
        $out = [];
        foreach ($items as $n => $it) {
            $msgs = [];
            if ($it['date'] === null) {
                $msgs[] = 'Data inválida.';
            }
            if ($it['amount'] === null || $it['balance'] === null) {
                $msgs[] = 'Valor ou saldo ausente.';
            } elseif ($prev === null) {
                $opening = round($it['balance'] - $it['amount'], 2); // derivado da 1ª linha
            } elseif (abs(round($prev + $it['amount'], 2) - $it['balance']) >= 0.005) {
                $gap = round($it['balance'] - $it['amount'] - $prev, 2);
                $msgs[] = 'Saldo não fecha: faltam movimentos de R$ '.number_format($gap, 2, ',', '.').' antes desta linha (ou linha fora de ordem).';
                $mismatches[] = ['position' => $n + 1, 'date' => $it['date'], 'gap' => $gap];
            }
            if ($it['amount'] !== null) {
                $it['amount'] > 0 ? $credits += $it['amount'] : $debits += -$it['amount'];
            }
            if ($it['balance'] !== null) {
                $prev = $it['balance'];
            }
            $fp = sha1($it['date'].'|'.mb_strtolower($it['description']).'|'.$it['amount'].'|'.$it['balance']);
            $seen[$fp] = ($seen[$fp] ?? 0) + 1;
            $kind = self::classify($it['description'], (float) $it['amount']);
            $out[] = $it + ['kind' => $kind, 'fingerprint' => $fp.':'.$seen[$fp], 'messages' => $msgs, 'file' => $fileTag];
        }

        return ['rows' => $out, 'reconciliation' => [
            'order' => $reversed ? 'desc_invertido' : 'asc', 'rows' => count($out),
            'from' => $out[0]['date'] ?? null, 'to' => $out ? end($out)['date'] : null,
            'opening_derived' => $opening, 'credits' => round($credits, 2), 'debits' => round($debits, 2),
            'computed_final' => $opening === null ? null : round($opening + $credits - $debits, 2), 'last_balance' => $prev,
            'mismatches' => $mismatches, 'ok' => $mismatches === [] && $opening !== null && abs(round($opening + $credits - $debits, 2) - (float) $prev) < 0.005,
        ]];
    }

    /**
     * Junta vários arquivos do mesmo extrato: ordena por período, remove linhas repetidas entre arquivos (mesma impressão
     * digital: data+descrição+valor+saldo+ordinal) e confere a emenda (saldo final de um = abertura do próximo).
     * Duas linhas iguais no MESMO arquivo continuam distintas (ordinal).
     */
    public function combine(array $parsedFiles): array
    {
        usort($parsedFiles, fn ($a, $b) => strcmp((string) $a['reconciliation']['from'], (string) $b['reconciliation']['from']));
        $rows = [];
        $keys = [];
        $dupes = 0;
        $joins = [];
        $prevFile = null;
        $last = null;
        foreach ($parsedFiles as $f) {
            $checked = $prevFile === null;
            foreach ($f['rows'] as $r) {
                if (isset($keys[$r['fingerprint']])) {
                    $dupes++; // linha já vista em outro arquivo (sobreposição de período)

                    continue;
                }
                if (! $checked) {
                    // Emenda: saldo da última linha mantida + valor da 1ª linha nova = saldo dela
                    $joins[] = ['previous_to' => $prevFile['reconciliation']['to'], 'next_from' => $r['date'], 'previous_last_balance' => $last,
                        'continuous' => $last !== null && $r['amount'] !== null && abs(round($last + $r['amount'], 2) - (float) $r['balance']) < 0.005];
                    $checked = true;
                }
                $keys[$r['fingerprint']] = true;
                $rows[] = $r;
                if ($r['balance'] !== null) {
                    $last = $r['balance'];
                }
            }
            $prevFile = $f;
        }
        $kinds = collect($rows)->groupBy('kind')->map(fn ($g) => ['count' => $g->count(), 'credits' => round($g->where('amount', '>', 0)->sum('amount'), 2), 'debits' => round(-$g->where('amount', '<', 0)->sum('amount'), 2)])->all();

        return ['rows' => $rows, 'duplicates_removed' => $dupes, 'joins' => $joins, 'by_kind' => $kinds,
            'from' => $rows[0]['date'] ?? null, 'to' => $rows ? end($rows)['date'] : null,
            'ok' => collect($parsedFiles)->every(fn ($f) => $f['reconciliation']['ok']) && collect($joins)->every(fn ($j) => $j['continuous'])];
    }
}

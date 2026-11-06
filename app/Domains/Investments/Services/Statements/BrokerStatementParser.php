<?php

namespace App\Domains\Investments\Services\Statements;

use Illuminate\Support\Carbon;

/** Mapeamento genérico de colunas → campos normalizados. Não há layout validado por corretora: tudo passa por prévia. */
class BrokerStatementParser
{
    public const FIELDS = [
        'date' => 'Data (pagamento/liquidação)', 'ticker' => 'Ticker/ativo', 'type' => 'Tipo/histórico', 'quantity' => 'Quantidade',
        'unit_price' => 'Preço unitário/PM', 'gross' => 'Valor bruto', 'tax' => 'IR retido', 'fee' => 'Taxa/custos', 'net' => 'Valor líquido',
        'lending_rate' => 'Taxa do aluguel (%)', 'external_id' => 'ID/nº do lançamento',
    ];

    /** Sinônimos (normalizados sem acento, minúsculos). */
    private const SYNONYMS = [
        'date' => ['data', 'data pagamento', 'data de pagamento', 'pagamento', 'data liquidacao', 'liquidacao', 'data mov', 'data movimentacao', 'dt'],
        'ticker' => ['ticker', 'ativo', 'papel', 'codigo', 'cod', 'codigo de negociacao', 'produto', 'titulo'],
        'type' => ['tipo', 'evento', 'historico', 'descricao', 'movimentacao', 'tipo de evento', 'lancamento'],
        'quantity' => ['quantidade', 'qtd', 'qtde', 'quant'],
        'unit_price' => ['preco', 'preco unitario', 'preco medio', 'pm', 'valor unitario', 'cotacao'],
        'gross' => ['valor bruto', 'bruto', 'valor'],
        'tax' => ['ir', 'irrf', 'imposto', 'ir retido', 'imposto de renda'],
        'fee' => ['taxa', 'taxas', 'custos', 'corretagem', 'emolumentos', 'tarifa'],
        'net' => ['valor liquido', 'liquido', 'valor operacao', 'total', 'valor total', 'creditado'],
        'lending_rate' => ['taxa aluguel', 'taxa btc', 'taxa de aluguel', 'taxa a.a', 'taxa anual'],
        'external_id' => ['id', 'n', 'no', 'n.', 'numero', 'nr', 'protocolo', 'nota', 'contrato'],
    ];

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim(preg_replace('/[^a-z0-9.%]+/', ' ', $s));
    }

    /** @return array<string,string> campo => cabeçalho */
    public function suggestMapping(array $headers): array
    {
        $map = [];
        $used = [];
        foreach (self::SYNONYMS as $field => $syn) {
            foreach ($headers as $h) {
                if (in_array($h, $used, true)) {
                    continue;
                }
                if (in_array(self::norm($h), $syn, true)) {
                    $map[$field] = $h;
                    $used[] = $h;
                    break;
                }
            }
        }

        return $map;
    }

    public static function number(?string $v): ?float
    {
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return null;
        }
        $neg = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        $v = preg_replace('/[^0-9,.\-]/', '', $v);
        $v = ltrim($v, '-');
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        } elseif (substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }
        if (! is_numeric($v)) {
            return null;
        }

        return $neg ? -(float) $v : (float) $v;
    }

    public static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v)) { // serial do Excel
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'Y-m-d H:i:s', 'd/m/Y H:i', 'd/m/Y H:i:s'] as $f) {
            try {
                $d = Carbon::createFromFormat('!'.$f, $v);
                if ($d && $d->format($f) === $v) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function ticker(?string $v): ?string
    {
        if (preg_match('/\b([A-Z]{4}\d{1,2}[A-Z]?)\b/', strtoupper((string) $v), $m)) {
            return $m[1];
        }

        return null;
    }

    /** Classifica pelo texto do tipo/histórico. Ordem importa (reembolso antes de dividendo, etc.). */
    public static function kind(?string $type): string
    {
        $t = self::norm((string) $type);
        $rules = [
            'lending_reimbursement' => ['reembolso'],
            'lending_fee' => ['aluguel', 'btc', 'emprestimo', 'doador'],
            'jcp' => ['jcp', 'juros sobre capital', 'juros s capital', 'jrs cap'],
            'fii_income' => ['rendimento', 'rend.', 'rendimentos'],
            'dividend' => ['dividendo', 'dividendos'],
            'buy' => ['compra', 'aplicacao'],
            'sell' => ['venda', 'resgate'],
            'transfer' => ['transferencia', 'transf', 'custodia', 'ted', 'deposito', 'retirada'],
            'fee' => ['taxa', 'tarifa', 'corretagem', 'emolumento', 'custodia cobrada'],
        ];
        foreach ($rules as $kind => $words) {
            foreach ($words as $w) {
                if ($t !== '' && str_contains($t, $w)) {
                    return $kind;
                }
            }
        }

        return $t === '' ? 'unknown' : 'other';
    }

    /** Normaliza uma linha bruta com o mapeamento. @return array<string,mixed> */
    public function normalize(array $headers, array $raw, array $mapping): array
    {
        $get = function (string $field) use ($headers, $raw, $mapping) {
            $col = $mapping[$field] ?? null;
            $i = $col === null ? false : array_search($col, $headers, true);

            return $i === false ? null : ($raw[$i] ?? null);
        };
        $msgs = [];
        $date = self::date($get('date'));
        if ($date === null) {
            $msgs[] = 'Data ausente ou inválida.';
        }
        $tickerRaw = $get('ticker');
        $ticker = self::ticker($tickerRaw) ?? self::ticker($get('type'));
        $typeLabel = $get('type');
        $kind = self::kind($typeLabel);
        $gross = self::number($get('gross'));
        $tax = self::number($get('tax'));
        $fee = self::number($get('fee'));
        $net = self::number($get('net'));
        if ($net === null && $gross !== null) {
            $net = round($gross - abs((float) $tax) - abs((float) $fee), 2);
            $msgs[] = 'Líquido calculado: bruto − IR − taxas.';
        }
        if ($net === null) {
            $msgs[] = 'Valor ausente.';
        }
        if ($ticker === null && in_array($kind, ['dividend', 'jcp', 'fii_income', 'lending_fee', 'lending_reimbursement', 'buy', 'sell'], true)) {
            $msgs[] = 'Ticker não identificado.';
        }
        if (in_array($kind, ['unknown', 'other'], true)) {
            $msgs[] = 'Tipo não reconhecido: revise.';
        }

        return [
            'date' => $date, 'ticker' => $ticker, 'kind' => $kind, 'source_type_label' => $typeLabel ? mb_substr($typeLabel, 0, 250) : null,
            'quantity' => self::number($get('quantity')), 'unit_price' => self::number($get('unit_price')),
            'gross_amount' => $gross, 'withholding_tax' => $tax !== null ? abs($tax) : null, 'fee_amount' => $fee !== null ? abs($fee) : null,
            'net_amount' => $net, 'lending_fee_rate' => self::number($get('lending_rate')),
            'external_id' => ($e = trim((string) $get('external_id'))) !== '' ? mb_substr($e, 0, 120) : null,
            'messages' => $msgs,
        ];
    }
}

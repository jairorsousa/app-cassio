<?php

namespace App\Domains\Banking\Services\Statements;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Prepara a revisão: tipo, categoria sugerida (pelo histórico da mesma descrição), "já existe",
 * "possível duplicata" e "provável transferência entre contas". Só lê o banco.
 */
class StatementImportAnalyzer
{
    private const TRANSFER_WORDS = '/\b(transf|ted|doc|pix (enviado|recebido) (para|de) (o )?pr[oó]prio|resgate|aplica[cç][aã]o|retirada em c\/c|conta investimento|conta digital)/iu';

    public static function normalize(string $d): string
    {
        $d = mb_strtolower($d);
        $d = preg_replace('/[0-9]+/', ' ', $d);
        $d = preg_replace('/[^\p{L} ]+/u', ' ', $d);

        return mb_substr(trim(preg_replace('/\s+/', ' ', $d)), 0, 40);
    }

    /**
     * @param  list<array{date: string, description: string, amount: float}>  $rows
     * @param  'bank'|'card'  $target
     * @return list<array<string, mixed>>
     */
    public function analyze(array $rows, string $target, int $targetId): array
    {
        if ($rows === []) {
            return [];
        }
        $dates = array_column($rows, 'date');
        $from = Carbon::parse(min($dates))->subDays(3)->toDateString();
        $to = Carbon::parse(max($dates))->addDays(3)->toDateString();
        $col = $target === 'card' ? 'credit_card_id' : 'bank_account_id';
        $same = Transaction::query()->where($col, $targetId)->whereBetween('date', [$from, $to])
            ->get(['id', 'date', 'amount', 'type', 'description', 'ofx_fitid']);
        $others = $target === 'bank'
            ? Transaction::query()->whereNotNull('bank_account_id')->where('bank_account_id', '!=', $targetId)->whereBetween('date', [$from, $to])->get(['id', 'date', 'amount', 'type', 'description', 'bank_account_id'])
            : collect();
        $accountNames = BankAccount::query()->pluck('name', 'id');
        $history = [];
        foreach (Transaction::query()->whereNotNull('category_id')->orderByDesc('date')->limit(8000)->get(['description', 'category_id', 'type']) as $t) {
            $k = self::normalize((string) $t->description).'|'.$t->type;
            $history[$k] ??= $t->category_id;
        }
        $fitids = $same->pluck('ofx_fitid')->filter()->flip();

        $cardSign = 1;
        if ($target === 'card') {
            $neg = count(array_filter($rows, fn ($r) => $r['amount'] < 0));
            $cardSign = $neg > count($rows) / 2 ? -1 : 1; // sinal das compras na fatura
        }

        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $amount = (float) $r['amount'];
            $abs = round(abs($amount), 2);
            $norm = self::normalize($r['description']);
            $occ = $seen[$r['date'].'|'.$amount.'|'.$norm] = ($seen[$r['date'].'|'.$amount.'|'.$norm] ?? 0) + 1;
            $fitid = 'stmt:'.substr(sha1($target.'|'.$targetId.'|'.$r['date'].'|'.number_format($amount, 2, '.', '').'|'.$norm.'|'.$occ), 0, 32);
            if ($target === 'card') {
                $kind = preg_match('/pagamento|pagto|pgto/iu', $r['description']) && ($amount * $cardSign) < 0 ? 'payment' : (($amount * $cardSign) < 0 ? 'refund' : 'purchase');
                $type = 'expense';
            } else {
                $kind = null;
                $type = $amount < 0 ? 'expense' : 'income';
            }
            $status = 'new';
            $note = '';
            $exists = isset($fitids[$fitid]) || $same->contains(fn ($t) => $t->date->toDateString() === $r['date'] && round(abs((float) $t->amount), 2) === $abs && self::normalize((string) $t->description) === $norm);
            if ($exists) {
                $status = 'exists';
                $note = 'Já existe neste destino (mesma data, valor e descrição).';
            } elseif ($dup = $same->first(fn ($t) => round(abs((float) $t->amount), 2) === $abs && abs($t->date->diffInDays(Carbon::parse($r['date']))) <= 2)) {
                $status = 'duplicate';
                $note = 'Possível duplicata: já há '.$dup->date->format('d/m').' R$ '.number_format($abs, 2, ',', '.').' · '.mb_substr((string) $dup->description, 0, 40);
            } elseif ($kind === 'payment') {
                $status = 'payment';
                $note = 'Pagamento da fatura: registre pelo botão Pagar da fatura, não como compra.';
            } elseif ($target === 'bank' && ($tr = $others->first(fn ($t) => round(abs((float) $t->amount), 2) === $abs && abs($t->date->diffInDays(Carbon::parse($r['date']))) <= 2 && (($amount < 0 && $t->type !== 'expense') || ($amount > 0 && $t->type !== 'income'))))) {
                $status = 'transfer';
                $note = 'Provável transferência entre contas: '.($amount < 0 ? 'entrada' : 'saída').' de mesmo valor em '.($accountNames[$tr->bank_account_id] ?? 'outra conta').' em '.$tr->date->format('d/m').'. Se já foi lançada lá como transferência, não aprove.';
            } elseif (preg_match(self::TRANSFER_WORDS, $r['description'])) {
                $status = 'transfer_hint';
                $note = 'Descrição parece transferência entre contas próprias: confira antes de aprovar como receita/despesa.';
            }
            $out[] = [
                'i' => $i,
                'date' => $r['date'],
                'description' => mb_substr($r['description'], 0, 255),
                'amount' => number_format($abs, 2, '.', ''),
                'signed' => $amount,
                'type' => $type,
                'card_kind' => $kind,
                'category_id' => $history[$norm.'|'.$type] ?? null,
                'fitid' => $fitid,
                'status' => $status,
                'note' => $note,
                'selected' => $status === 'new',
            ];
        }

        return $out;
    }
}

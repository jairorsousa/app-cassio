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
    /** Contraparte = o próprio titular (Cassio): nome ou CPF mascarado ***.394.813***. */
    public const OWNER_PATTERN = '/cass[ií]o\s+mota|\*{0,3}\s*\.?\s*394\.?813\b/iu';

    /** Linhas que não são lançamento: saldo do dia, saldo anterior etc. */
    private const BALANCE_LINE = '/\bsaldo\s+(dia|do\s+dia|anterior|final|atual|bloqueado|dispon[ií]vel)\b|^\W*saldo\W*$/iu';

    private const BANK_HINTS = ['ita[uú]' => 'ita', 'bradesco' => 'bradesco', 'nubank|nu pagamentos' => 'nubank', 'btg' => 'btg', '\bxp\b' => 'xp', 'banco do brasil|\bbb\b' => 'banco do brasil', 'caixa|\bcef\b' => 'cef', 'santander' => 'santander', 'inter\b' => 'inter'];

    public int $ignored = 0;

    private const TRANSFER_WORDS = '/\b(transf|ted|doc|pix (enviado|recebido) (para|de) (o )?pr[oó]prio|resg(ate)?\b|aplica[cç][aã]o|retirada em c\/c|conta investimento|conta digital)/iu';

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
        $this->ignored = 0;
        $rows = array_values(array_filter($rows, function ($r) {
            $skip = round(abs((float) $r['amount']), 2) == 0.0 || preg_match(self::BALANCE_LINE, (string) $r['description']);
            $this->ignored += $skip ? 1 : 0;

            return ! $skip;
        }));
        if ($rows === []) {
            return [];
        }
        $dates = array_column($rows, 'date');
        $from = Carbon::parse(min($dates))->subDays(5)->toDateString();
        $to = Carbon::parse(max($dates))->addDays(5)->toDateString();
        $col = $target === 'card' ? 'credit_card_id' : 'bank_account_id';
        $same = Transaction::query()->where($col, $targetId)->whereBetween('date', [$from, $to])
            ->get(['id', 'date', 'amount', 'type', 'description', 'ofx_fitid']);
        $others = $target === 'bank'
            ? Transaction::query()->whereNotNull('bank_account_id')->where('bank_account_id', '!=', $targetId)->whereBetween('date', [$from, $to])->get(['id', 'date', 'amount', 'type', 'description', 'bank_account_id', 'related_transaction_id'])
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
            // Nr. do documento do banco (TXT/HTML/PDF da Caixa): mesmo lançamento em qualquer formato → mesma chave.
            $doc = preg_match('/^\d{4,10}$/', (string) ($r['doc'] ?? '')) && ! preg_match('/^0+$/', (string) $r['doc']) ? ltrim((string) $r['doc'], '0') : null;
            $docKey = $doc !== null ? 'doc:'.$doc.'|'.number_format($abs, 2, '.', '').'|' : null;
            $fitid = ! empty($r['fitid']) ? (string) $r['fitid'] : ($docKey !== null ? substr($docKey.$r['date'].'|'.$occ, 0, 64) : 'stmt:'.substr(sha1($target.'|'.$targetId.'|'.$r['date'].'|'.number_format($amount, 2, '.', '').'|'.$norm.'|'.$occ), 0, 32));
            if ($target === 'card') {
                $kind = preg_match('/pagamento|pagto|pgto/iu', $r['description']) && ($amount * $cardSign) < 0 ? 'payment' : (($amount * $cardSign) < 0 ? 'refund' : 'purchase');
                $type = 'expense';
            } else {
                $kind = null;
                $type = $amount < 0 ? 'expense' : 'income';
            }
            $status = 'new';
            $note = '';
            $transferAccount = null;
            $linkId = null;
            $own = $target === 'bank' && preg_match(self::OWNER_PATTERN, $r['description']);
            $counter = $target === 'bank' ? self::counterpart($others, $amount, $r['date']) : null;
            $exists = isset($fitids[$fitid])
                || ($docKey !== null && $same->contains(fn ($t) => str_starts_with((string) $t->ofx_fitid, $docKey) && abs($t->date->diffInDays(Carbon::parse($r['date']), false)) <= 4))
                || $same->contains(fn ($t) => $t->date->toDateString() === $r['date'] && round(abs((float) $t->amount), 2) === $abs && self::normalize((string) $t->description) === $norm);
            if ($exists) {
                $status = 'exists';
                $note = 'Já existe neste destino (mesma data, valor e descrição).';
            } elseif ($dup = $same->first(fn ($t) => round(abs((float) $t->amount), 2) === $abs && abs($t->date->diffInDays(Carbon::parse($r['date']))) <= 2)) {
                $status = 'duplicate';
                $note = 'Possível duplicata: já há '.$dup->date->format('d/m').' R$ '.number_format($abs, 2, ',', '.').' · '.mb_substr((string) $dup->description, 0, 40);
            } elseif ($kind === 'payment') {
                $status = 'payment';
                $note = 'Pagamento da fatura: registre pelo botão Pagar da fatura, não como compra.';
            } elseif ($own || $counter) {
                $status = 'transfer';
                $type = 'transfer';
                $transferAccount = $counter?->bank_account_id ?? $this->guessAccount($r['description'], $targetId);
                $linkId = $counter?->id;
                $note = ($own ? 'Contraparte é o próprio titular: transferência entre contas suas. ' : 'Provável transferência entre contas. ')
                    .($counter ? 'Já existe a '.($amount < 0 ? 'entrada' : 'saída').' de mesmo valor em '.($accountNames[$counter->bank_account_id] ?? 'outra conta').' em '.$counter->date->format('d/m').': será vinculada, sem duplicar.'
                        : 'Confira a conta de '.($amount < 0 ? 'destino' : 'origem').'.');
            } elseif ($target === 'bank' && $abs >= 1000 && fmod($abs, 1000) == 0.0 && preg_match('/\b(pix|ted|doc|transf)/iu', $r['description'])) {
                // Extratos sem favorecido (ex.: OFX da Caixa só diz "DEB PIX CHAVE"): PIX/TED de valor redondo costuma ser entre contas suas.
                $status = 'transfer_hint';
                $type = 'transfer';
                $transferAccount = $this->guessAccount($r['description'], $targetId);
                $note = 'PIX/TED de valor redondo sem favorecido no arquivo: provável transferência entre suas contas. Confira a conta e marque para gravar.';
            } elseif (preg_match(self::TRANSFER_WORDS, $r['description'])) {
                $status = 'transfer_hint';
                $note = 'Descrição parece transferência entre contas próprias: confira antes de aprovar como receita/despesa.';
            }
            $out[] = [
                'i' => $i,
                'date' => $r['date'],
                'description' => mb_substr(self::clean($r['description']), 0, 255),
                'amount' => number_format($abs, 2, ',', '.'),
                'signed' => $amount,
                'type' => $type,
                'card_kind' => $kind,
                'card_final' => $r['final'] ?? null,
                'category_id' => $type === 'transfer' ? null : ($history[$norm.'|'.$type] ?? null),
                'transfer_account_id' => $transferAccount,
                'transfer_link_id' => $linkId,
                'fitid' => $fitid,
                'status' => $status,
                'note' => $note,
                'selected' => $status === 'new' || ($status === 'transfer' && $own),
            ];
        }

        return $out;
    }

    /** Tira o "- 06:04:00 230604" (hora e nº do documento) do começo da descrição de extratos em PDF. */
    public static function clean(string $d): string
    {
        $c = preg_replace('/^[-\s]*\d{2}:\s?\d{2}(:\s?\d{2})?\s+\d{3,}\s+/u', '', trim($d));

        return trim($c) !== '' ? trim($c) : trim($d);
    }

    /**
     * Contrapartida já lançada em outra conta: mesmo valor, ±2 dias, sentido oposto e ainda sem par.
     * Saída aqui (valor < 0) casa com receita ou entrada de transferência lá; entrada aqui casa com despesa ou saída lá.
     */
    public static function counterpart(iterable $others, float $signed, string $date, ?int $onlyAccount = null): ?Transaction
    {
        $abs = round(abs($signed), 2);
        $d = Carbon::parse($date);
        foreach ($others as $t) {
            if ($onlyAccount && (int) $t->bank_account_id !== $onlyAccount) {
                continue;
            }
            if (round(abs((float) $t->amount), 2) !== $abs || abs($t->date->diffInDays($d)) > 2) {
                continue;
            }
            $ok = $signed < 0
                ? ($t->type === 'income' || ($t->type === 'transfer' && (float) $t->amount > 0 && ! $t->related_transaction_id))
                : ($t->type === 'expense' || ($t->type === 'transfer' && (float) $t->amount < 0 && ! $t->related_transaction_id));
            if ($ok) {
                return $t;
            }
        }

        return null;
    }

    public static function findCounterpart(int $targetId, int $otherId, float $signed, string $date): ?Transaction
    {
        $d = Carbon::parse($date);
        $others = Transaction::query()->where('bank_account_id', $otherId)->where('bank_account_id', '!=', $targetId)
            ->whereBetween('date', [$d->copy()->subDays(2)->toDateString(), $d->copy()->addDays(2)->toDateString()])
            ->get(['id', 'date', 'amount', 'type', 'description', 'bank_account_id', 'related_transaction_id'])
            ->sortBy(fn ($t) => abs($t->date->diffInDays($d)));

        return self::counterpart($others, $signed, $date, $otherId);
    }

    /** Conta provável: banco citado na descrição; senão, a conta com que esta mais transfere. */
    private function guessAccount(string $description, int $targetId): ?int
    {
        // Só contas próprias de movimento (não repasse/cliente/fundo automático).
        $accounts = BankAccount::query()->where('id', '!=', $targetId)->where('status', true)->where('type', 'checking')
            ->where('name', 'not like', '%repasse%')->where('name', 'not like', '%cliente%')->where('name', 'not like', '%advogados%')->get(['id', 'name']);
        foreach (self::BANK_HINTS as $re => $needle) {
            if (preg_match('/'.$re.'/iu', $description) && ($a = $accounts->first(fn ($a) => str_contains(mb_strtolower($a->name), $needle)))) {
                return $a->id;
            }
        }
        $ids = Transaction::query()->where('bank_account_id', $targetId)->where('type', 'transfer')->whereNotNull('related_transaction_id')->pluck('related_transaction_id');
        $top = Transaction::query()->whereIn('id', $ids)->whereIn('bank_account_id', $accounts->pluck('id'))
            ->selectRaw('bank_account_id, COUNT(*) c')->groupBy('bank_account_id')->orderByDesc('c')->first();

        return $top?->bank_account_id; // sem histórico: fica em branco para não sugerir errado
    }
}

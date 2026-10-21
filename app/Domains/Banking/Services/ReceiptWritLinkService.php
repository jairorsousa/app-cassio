<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReceiptWritLinkService
{
    public function availableIncomes(ReceiptDestination $part): Builder
    {
        return Transaction::with('sourceWrit')->where('source_type', Writ::class)
            ->where('type', 'income')->where('status', 'settled')->where('amount', '>', 0)
            ->whereNull('credit_card_id')->whereDate('date', '<=', now('America/Sao_Paulo')->toDateString())
            ->where('bank_account_id', $part->receipt->bank_account_id)
            ->whereHas('sourceWrit', fn ($q) => $q->where('stage', 'finalized')->where('paid_amount', '>', 0));
    }

    public function link(ReceiptDestination $part, int $incomeId, string $amount, ?string $requestKey = null): void
    {
        DB::transaction(function () use ($part, $incomeId, $amount, $requestKey) {
            $receipt = Transaction::lockForUpdate()->findOrFail($part->receipt_id);
            app(ReceiptDestinationService::class)->eligible($receipt);
            $part = ReceiptDestination::lockForUpdate()->findOrFail($part->id);
            if ($part->kind !== 'client' || ! $part->repasse_account_id) {
                throw new \DomainException('O vínculo com requisitório está disponível somente para valores do cliente em conta de repasse.');
            }
            if ($requestKey && ($existing = ReceiptDestinationPayment::where('request_key', $requestKey)->first())) {
                if ($existing->destination_id !== $part->id || $existing->writ_income_id !== $incomeId) {
                    throw new \DomainException('Esta confirmação já foi usada em outra baixa.');
                }

                return;
            }
            $candidate = Transaction::where('source_type', Writ::class)->find($incomeId);
            $writ = $candidate ? Writ::lockForUpdate()->find($candidate->source_id) : null;
            $income = $writ ? $this->availableIncomes($part)->lockForUpdate()->find($incomeId) : null;
            if (! $income || $income->source_id !== $writ->id) {
                throw new \DomainException('Selecione um requisitório comprado e recebido, com saldo disponível na mesma conta deste recebimento.');
            }
            $values = app(ReceiptDestinationService::class);
            $cents = $values->cents($amount);
            if ($cents > $part->remainingCents()) {
                throw new \DomainException('O valor vinculado não pode ultrapassar o saldo do cliente.');
            }
            $available = $values->cents((string) $income->amount);
            if ($cents > $available) {
                throw new \DomainException('O valor vinculado não pode ultrapassar o saldo disponível do requisitório.');
            }
            $payment = $part->payments()->create([
                'transaction_created' => false, 'request_key' => $requestKey,
                'writ_income_id' => $income->id, 'writ_amount' => $this->money($cents),
            ]);
            // This is the same bank credit already present in the process receipt.
            // Keep the original Writ transaction (even at zero) so stage events cannot recreate it.
            $income->update(['amount' => $this->money($available - $cents)]);
            app(ReceiptAccountAllocationService::class)->settle($payment);
            $receipt->touch();
        });
    }

    /** Called inside the receipt/payment deletion transaction; preserve the original Writ. */
    public function restoreIncome(ReceiptDestinationPayment $payment): void
    {
        if (! $payment->writ_income_id) {
            return;
        }
        $income = Transaction::lockForUpdate()->findOrFail($payment->writ_income_id);
        $values = app(ReceiptDestinationService::class);
        $income->update(['amount' => $this->money($values->cents((string) $income->amount, true) + $values->cents((string) $payment->writ_amount))]);
    }

    public function guardWrit(Writ $writ): void
    {
        if (ReceiptDestinationPayment::whereHas('writIncome', fn ($q) => $q->where('source_type', Writ::class)->where('source_id', $writ->id))->exists()) {
            throw new \DomainException('Desvincule este requisitório em Contas → Gerenciar repasses antes de alterar seu recebimento ou excluí-lo.');
        }
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

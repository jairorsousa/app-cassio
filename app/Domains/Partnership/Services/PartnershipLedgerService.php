<?php

namespace App\Domains\Partnership\Services;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Partnership\Models\PartnershipExpense;
use Illuminate\Support\Facades\DB;

class PartnershipLedgerService
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function syncContribution(PartnershipContribution $contribution): void
    {
        $contribution->loadMissing('partnership');

        $shouldHaveTransaction = $contribution->status === 'done' && $contribution->bank_account_id;

        $this->syncSource(
            $contribution,
            $shouldHaveTransaction,
            [
                'type' => 'expense',
                'date' => $contribution->date,
                'amount' => $contribution->amount,
                'description' => 'Aporte sociedade · '.($contribution->partnership?->name ?? '#'.$contribution->partnership_id),
                'status' => 'settled',
                'bank_account_id' => $contribution->bank_account_id,
            ],
        );
    }

    public function syncExpense(PartnershipExpense $expense): void
    {
        $expense->loadMissing('partnership');

        $shouldHaveTransaction = $expense->bank_account_id && (float) $expense->proportional_amount > 0;

        $this->syncSource(
            $expense,
            $shouldHaveTransaction,
            [
                'type' => 'expense',
                'date' => $expense->date,
                'amount' => $expense->proportional_amount,
                'description' => 'Despesa sociedade ('.number_format((float) $expense->applied_percentage, 2, ',', '.').'%) · '.$expense->description,
                'status' => 'settled',
                'category_id' => $expense->category_id,
                'bank_account_id' => $expense->bank_account_id,
            ],
        );
    }

    public function syncDistribution(PartnershipDistribution $distribution): void
    {
        $distribution->loadMissing('partnership');

        $shouldHaveTransaction = (bool) $distribution->bank_account_id;

        $this->syncSource(
            $distribution,
            $shouldHaveTransaction,
            [
                'type' => 'income',
                'date' => $distribution->date,
                'amount' => $distribution->amount,
                'description' => 'Distribuição sociedade · '.($distribution->partnership?->name ?? '#'.$distribution->partnership_id).($distribution->source ? ' ('.$distribution->source.')' : ''),
                'status' => 'settled',
                'bank_account_id' => $distribution->bank_account_id,
            ],
        );
    }

    public function deleteLinkedTransactions(PartnershipContribution|PartnershipExpense|PartnershipDistribution $source): void
    {
        $source->transactions()->get()->each(
            fn (Transaction $transaction) => $this->transactions->deleteGenerated($transaction)
        );
    }

    public function deletePartnership(Partnership $partnership): void
    {
        DB::transaction(function () use ($partnership) {
            $partnership = Partnership::query()->lockForUpdate()->findOrFail($partnership->id);

            $partnership->contributions()->get()->each(fn (PartnershipContribution $item) => $item->delete());
            $partnership->expenses()->get()->each(fn (PartnershipExpense $item) => $item->delete());
            $partnership->distributions()->get()->each(fn (PartnershipDistribution $item) => $item->delete());

            $partnership->delete();
        });
    }

    public function resyncDescriptions(Partnership $partnership): void
    {
        $partnership->contributions()->get()->each(fn (PartnershipContribution $item) => $this->syncContribution($item));
        $partnership->expenses()->get()->each(fn (PartnershipExpense $item) => $this->syncExpense($item));
        $partnership->distributions()->get()->each(fn (PartnershipDistribution $item) => $this->syncDistribution($item));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncSource(
        PartnershipContribution|PartnershipExpense|PartnershipDistribution $source,
        bool $shouldHaveTransaction,
        array $payload,
    ): void {
        $transaction = $source->transactions()->first();

        if (! $shouldHaveTransaction) {
            if ($transaction) {
                $this->transactions->deleteGenerated($transaction);
            }

            return;
        }

        $payload['source_type'] = $source::class;
        $payload['source_id'] = $source->id;

        if ($transaction) {
            $this->transactions->updateGenerated($transaction, $payload);

            return;
        }

        $this->transactions->create($payload);
    }
}

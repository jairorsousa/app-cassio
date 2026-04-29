<?php

namespace App\Domains\Writs\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Writs\Events\WritMovedToFinalized;

class CreateIncomeOnFinalized
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(WritMovedToFinalized $event): void
    {
        $writ = $event->writ;

        if ($writ->actual_receipt_amount === null || (float) $writ->actual_receipt_amount <= 0) {
            return;
        }

        $existing = $writ->transactions()->where('type', 'income')->exists();
        if ($existing) {
            return;
        }

        $this->transactions->create([
            'type' => 'income',
            'date' => $writ->finalized_at ?? now()->toDateString(),
            'amount' => $writ->actual_receipt_amount,
            'description' => 'Recebimento requisitório · '.($writ->process_number ?: '#'.$writ->id),
            'status' => 'settled',
            'bank_account_id' => $writ->destination_bank_account_id,
            'source_type' => $writ::class,
            'source_id' => $writ->id,
        ]);
    }
}

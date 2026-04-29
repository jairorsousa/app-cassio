<?php

namespace App\Domains\Writs\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Writs\Events\WritMovedToPaid;

class CreateExpenseOnPaid
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(WritMovedToPaid $event): void
    {
        $writ = $event->writ;

        if ((float) $writ->paid_amount <= 0) {
            return;
        }

        $existing = $writ->transactions()->where('type', 'expense')->exists();
        if ($existing) {
            return;
        }

        $this->transactions->create([
            'type' => 'expense',
            'date' => $writ->paid_at ?? now()->toDateString(),
            'amount' => $writ->paid_amount,
            'description' => 'Aquisição requisitório · '.($writ->process_number ?: '#'.$writ->id),
            'status' => 'settled',
            'bank_account_id' => $writ->source_bank_account_id,
            'source_type' => $writ::class,
            'source_id' => $writ->id,
        ]);
    }
}

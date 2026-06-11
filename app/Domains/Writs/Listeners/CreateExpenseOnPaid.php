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

        $existing = $writ->transactions()->where('type', 'expense')->exists();
        if ($existing) {
            return;
        }

        if ((float) $writ->paid_amount > 0) {
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

        if ((float) $writ->notary_expenses_amount > 0) {
            $this->transactions->create([
                'type' => 'expense',
                'date' => $writ->paid_at ?? now()->toDateString(),
                'amount' => $writ->notary_expenses_amount,
                'description' => 'Despesas cartorárias · '.($writ->process_number ?: '#'.$writ->id),
                'status' => 'settled',
                'bank_account_id' => $writ->source_bank_account_id,
                'source_type' => $writ::class,
                'source_id' => $writ->id,
            ]);
        }

        if ((float) $writ->other_expenses_amount > 0) {
            $this->transactions->create([
                'type' => 'expense',
                'date' => $writ->paid_at ?? now()->toDateString(),
                'amount' => $writ->other_expenses_amount,
                'description' => 'Outras despesas · '.($writ->process_number ?: '#'.$writ->id),
                'status' => 'settled',
                'bank_account_id' => $writ->source_bank_account_id,
                'source_type' => $writ::class,
                'source_id' => $writ->id,
            ]);
        }
    }
}

<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Partnership\Events\PartnershipExpenseRecorded;

class CreateExpenseOnPartnershipExpense
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(PartnershipExpenseRecorded $event): void
    {
        $e = $event->expense;

        if ((float) $e->proportional_amount <= 0 || ! $e->bank_account_id) {
            return;
        }

        if ($e->transactions()->exists()) {
            return;
        }

        $this->transactions->create([
            'type' => 'expense',
            'date' => $e->date,
            'amount' => $e->proportional_amount,
            'description' => 'Despesa sociedade ('.number_format((float) $e->applied_percentage, 2, ',', '.').'%) · '.$e->description,
            'status' => 'settled',
            'category_id' => $e->category_id,
            'bank_account_id' => $e->bank_account_id,
            'source_type' => $e::class,
            'source_id' => $e->id,
        ]);
    }
}

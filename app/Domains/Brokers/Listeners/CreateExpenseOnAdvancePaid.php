<?php

namespace App\Domains\Brokers\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Events\BrokerAdvancePaid;

class CreateExpenseOnAdvancePaid
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(BrokerAdvancePaid $event): void
    {
        $advance = $event->advance;

        if ((float) $advance->amount <= 0) {
            return;
        }

        $this->transactions->create([
            'type' => 'expense',
            'date' => $advance->date->toDateString(),
            'amount' => $advance->amount,
            'description' => 'Adiantamento corretor · '.$advance->broker->name,
            'status' => 'settled',
            'bank_account_id' => $advance->bank_account_id,
            'source_type' => $advance->broker::class,
            'source_id' => $advance->broker_id,
        ]);
    }
}

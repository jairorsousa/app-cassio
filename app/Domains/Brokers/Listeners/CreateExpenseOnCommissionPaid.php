<?php

namespace App\Domains\Brokers\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Events\BrokerCommissionPaid;

class CreateExpenseOnCommissionPaid
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(BrokerCommissionPaid $event): void
    {
        $commission = $event->commission;
        $netAmount = $commission->remainingAmount();

        if ($netAmount <= 0) {
            return;
        }

        $this->transactions->create([
            'type' => 'expense',
            'date' => now()->toDateString(),
            'amount' => $netAmount,
            'description' => 'Comissão corretor · '.$commission->broker->name
                .' · '.$commission->caseType->name,
            'status' => 'settled',
            'bank_account_id' => $commission->bank_account_id,
            'source_type' => $commission->broker::class,
            'source_id' => $commission->broker_id,
        ]);
    }
}

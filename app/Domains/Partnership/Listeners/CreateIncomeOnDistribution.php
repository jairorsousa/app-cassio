<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Partnership\Events\PartnershipDistributionReceived;

class CreateIncomeOnDistribution
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(PartnershipDistributionReceived $event): void
    {
        $d = $event->distribution;

        if (! $d->bank_account_id) {
            return;
        }

        if ($d->transactions()->exists()) {
            return;
        }

        $this->transactions->create([
            'type' => 'income',
            'date' => $d->date,
            'amount' => $d->amount,
            'description' => 'Distribuição sociedade · '.($d->partnership?->name ?? '#'.$d->partnership_id).($d->source ? ' ('.$d->source.')' : ''),
            'status' => 'settled',
            'bank_account_id' => $d->bank_account_id,
            'source_type' => $d::class,
            'source_id' => $d->id,
        ]);
    }
}

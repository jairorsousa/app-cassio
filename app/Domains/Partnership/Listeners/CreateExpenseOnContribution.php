<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Partnership\Events\PartnershipContributionMade;

class CreateExpenseOnContribution
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(PartnershipContributionMade $event): void
    {
        $c = $event->contribution;

        if ($c->status !== 'done' || ! $c->bank_account_id) {
            return;
        }

        if ($c->transactions()->exists()) {
            return;
        }

        $this->transactions->create([
            'type' => 'expense',
            'date' => $c->date,
            'amount' => $c->amount,
            'description' => 'Aporte sociedade · '.($c->partnership?->name ?? '#'.$c->partnership_id),
            'status' => 'settled',
            'bank_account_id' => $c->bank_account_id,
            'source_type' => $c::class,
            'source_id' => $c->id,
        ]);
    }
}

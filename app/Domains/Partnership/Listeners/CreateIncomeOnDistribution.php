<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Partnership\Events\PartnershipDistributionReceived;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class CreateIncomeOnDistribution
{
    public function __construct(private PartnershipLedgerService $ledger)
    {
    }

    public function handle(PartnershipDistributionReceived $event): void
    {
        $this->ledger->syncDistribution($event->distribution);
    }
}

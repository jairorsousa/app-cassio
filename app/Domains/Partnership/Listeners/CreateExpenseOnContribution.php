<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Partnership\Events\PartnershipContributionMade;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class CreateExpenseOnContribution
{
    public function __construct(private PartnershipLedgerService $ledger)
    {
    }

    public function handle(PartnershipContributionMade $event): void
    {
        $this->ledger->syncContribution($event->contribution);
    }
}

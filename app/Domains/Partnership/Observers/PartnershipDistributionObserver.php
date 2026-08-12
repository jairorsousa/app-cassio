<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Partnership\Events\PartnershipDistributionReceived;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class PartnershipDistributionObserver
{
    public function __construct(
        private PartnershipLedgerService $ledger,
        private DashboardRefreshTrigger $trigger,
    ) {
    }

    public function created(PartnershipDistribution $distribution): void
    {
        PartnershipDistributionReceived::dispatch($distribution);
        ($this->trigger)();
    }

    public function updated(PartnershipDistribution $distribution): void
    {
        $this->ledger->syncDistribution($distribution);
        ($this->trigger)();
    }

    public function deleted(PartnershipDistribution $distribution): void
    {
        $this->ledger->deleteLinkedTransactions($distribution);
        ($this->trigger)();
    }
}

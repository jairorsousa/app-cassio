<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Partnership\Events\PartnershipContributionMade;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class PartnershipContributionObserver
{
    public function __construct(
        private PartnershipLedgerService $ledger,
        private DashboardRefreshTrigger $trigger,
    ) {
    }

    public function created(PartnershipContribution $contribution): void
    {
        if ($contribution->status === 'done') {
            PartnershipContributionMade::dispatch($contribution);
        }

        ($this->trigger)();
    }

    public function updated(PartnershipContribution $contribution): void
    {
        $this->ledger->syncContribution($contribution);
        ($this->trigger)();
    }

    public function deleted(PartnershipContribution $contribution): void
    {
        $this->ledger->deleteLinkedTransactions($contribution);
        ($this->trigger)();
    }
}

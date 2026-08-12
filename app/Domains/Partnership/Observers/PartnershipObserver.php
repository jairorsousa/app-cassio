<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class PartnershipObserver
{
    public function __construct(
        private PartnershipLedgerService $ledger,
        private DashboardRefreshTrigger $trigger,
    ) {
    }

    public function saved(Partnership $partnership): void
    {
        if ($partnership->wasChanged('name')) {
            $this->ledger->resyncDescriptions($partnership);
        }

        ($this->trigger)();
    }

    public function deleted(Partnership $partnership): void
    {
        ($this->trigger)();
    }
}

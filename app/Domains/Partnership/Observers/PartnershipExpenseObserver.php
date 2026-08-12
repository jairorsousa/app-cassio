<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Partnership\Events\PartnershipExpenseRecorded;
use App\Domains\Partnership\Models\PartnershipExpense;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class PartnershipExpenseObserver
{
    public function __construct(
        private PartnershipLedgerService $ledger,
        private DashboardRefreshTrigger $trigger,
    ) {
    }

    public function created(PartnershipExpense $expense): void
    {
        PartnershipExpenseRecorded::dispatch($expense);
        ($this->trigger)();
    }

    public function updated(PartnershipExpense $expense): void
    {
        $this->ledger->syncExpense($expense);
        ($this->trigger)();
    }

    public function deleted(PartnershipExpense $expense): void
    {
        $this->ledger->deleteLinkedTransactions($expense);
        ($this->trigger)();
    }
}

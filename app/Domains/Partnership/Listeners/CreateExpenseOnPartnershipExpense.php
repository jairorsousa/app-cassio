<?php

namespace App\Domains\Partnership\Listeners;

use App\Domains\Partnership\Events\PartnershipExpenseRecorded;
use App\Domains\Partnership\Services\PartnershipLedgerService;

class CreateExpenseOnPartnershipExpense
{
    public function __construct(private PartnershipLedgerService $ledger)
    {
    }

    public function handle(PartnershipExpenseRecorded $event): void
    {
        $this->ledger->syncExpense($event->expense);
    }
}

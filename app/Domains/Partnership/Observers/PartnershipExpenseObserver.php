<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Partnership\Events\PartnershipExpenseRecorded;
use App\Domains\Partnership\Models\PartnershipExpense;

class PartnershipExpenseObserver
{
    public function created(PartnershipExpense $expense): void
    {
        PartnershipExpenseRecorded::dispatch($expense);
    }
}

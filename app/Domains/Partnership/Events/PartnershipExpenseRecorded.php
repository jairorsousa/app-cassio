<?php

namespace App\Domains\Partnership\Events;

use App\Domains\Partnership\Models\PartnershipExpense;
use Illuminate\Foundation\Events\Dispatchable;

class PartnershipExpenseRecorded
{
    use Dispatchable;

    public function __construct(public PartnershipExpense $expense)
    {
    }
}

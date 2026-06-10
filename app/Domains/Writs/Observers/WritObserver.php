<?php

namespace App\Domains\Writs\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Writs\Models\Writ;

class WritObserver
{
    public function __construct(private DashboardRefreshTrigger $trigger)
    {
    }

    public function saved(Writ $writ): void
    {
        $relevantFields = [
            'stage',
            'paid_amount',
            'negotiated_amount',
            'notary_expenses_amount',
            'other_expenses_amount',
            'face_value',
            'estimated_receipt_amount',
            'actual_receipt_amount',
            'cession_at',
            'paid_at',
            'finalized_at',
        ];
        foreach ($relevantFields as $f) {
            if ($writ->wasChanged($f)) {
                ($this->trigger)();
                return;
            }
        }
        if ($writ->wasRecentlyCreated) {
            ($this->trigger)();
        }
    }

    public function deleted(Writ $writ): void
    {
        ($this->trigger)();
    }
}

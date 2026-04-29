<?php

namespace App\Domains\Investments\Observers;

use App\Domains\Dashboard\Support\DashboardRefreshTrigger;
use App\Domains\Investments\Models\AssetPosition;

class AssetPositionObserver
{
    public function __construct(private DashboardRefreshTrigger $trigger)
    {
    }

    public function saved(AssetPosition $position): void
    {
        if ($position->wasChanged('current_price') || $position->wasChanged('quantity')) {
            ($this->trigger)();
        }
    }
}

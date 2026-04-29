<?php

namespace App\Domains\Brokers\Observers;

use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Dashboard\Support\DashboardRefreshTrigger;

class BrokerAdvanceObserver
{
    public function __construct(private DashboardRefreshTrigger $trigger)
    {
    }

    public function saved(BrokerAdvance $advance): void
    {
        ($this->trigger)();
    }

    public function deleted(BrokerAdvance $advance): void
    {
        ($this->trigger)();
    }
}

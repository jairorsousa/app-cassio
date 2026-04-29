<?php

namespace App\Domains\Brokers\Observers;

use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Dashboard\Support\DashboardRefreshTrigger;

class BrokerCommissionSettlementObserver
{
    public function __construct(private DashboardRefreshTrigger $trigger)
    {
    }

    public function saved(BrokerCommissionSettlement $settlement): void
    {
        ($this->trigger)();
    }

    public function deleted(BrokerCommissionSettlement $settlement): void
    {
        ($this->trigger)();
    }
}

<?php

namespace App\Domains\Brokers\Events;

use App\Domains\Brokers\Models\BrokerCommission;
use Illuminate\Foundation\Events\Dispatchable;

class BrokerCommissionPaid
{
    use Dispatchable;

    public function __construct(public BrokerCommission $commission)
    {
    }
}

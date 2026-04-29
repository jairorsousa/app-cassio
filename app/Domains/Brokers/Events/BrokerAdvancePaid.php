<?php

namespace App\Domains\Brokers\Events;

use App\Domains\Brokers\Models\BrokerAdvance;
use Illuminate\Foundation\Events\Dispatchable;

class BrokerAdvancePaid
{
    use Dispatchable;

    public function __construct(public BrokerAdvance $advance)
    {
    }
}

<?php

namespace App\Domains\Partnership\Events;

use App\Domains\Partnership\Models\PartnershipDistribution;
use Illuminate\Foundation\Events\Dispatchable;

class PartnershipDistributionReceived
{
    use Dispatchable;

    public function __construct(public PartnershipDistribution $distribution)
    {
    }
}

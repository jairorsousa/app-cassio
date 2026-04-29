<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Partnership\Events\PartnershipDistributionReceived;
use App\Domains\Partnership\Models\PartnershipDistribution;

class PartnershipDistributionObserver
{
    public function created(PartnershipDistribution $distribution): void
    {
        PartnershipDistributionReceived::dispatch($distribution);
    }
}

<?php

namespace App\Domains\Partnership\Events;

use App\Domains\Partnership\Models\PartnershipContribution;
use Illuminate\Foundation\Events\Dispatchable;

class PartnershipContributionMade
{
    use Dispatchable;

    public function __construct(public PartnershipContribution $contribution)
    {
    }
}

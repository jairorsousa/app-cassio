<?php

namespace App\Domains\Partnership\Observers;

use App\Domains\Partnership\Events\PartnershipContributionMade;
use App\Domains\Partnership\Models\PartnershipContribution;

class PartnershipContributionObserver
{
    public function created(PartnershipContribution $contribution): void
    {
        if ($contribution->status === 'done') {
            PartnershipContributionMade::dispatch($contribution);
        }
    }

    public function updated(PartnershipContribution $contribution): void
    {
        if ($contribution->wasChanged('status') && $contribution->status === 'done') {
            PartnershipContributionMade::dispatch($contribution);
        }
    }
}

<?php

namespace App\Domains\Investments\Observers;

use App\Domains\Investments\Events\DividendReceived;
use App\Domains\Investments\Models\AssetDividend;

class AssetDividendObserver
{
    public function created(AssetDividend $div): void
    {
        DividendReceived::dispatch($div);
    }
}

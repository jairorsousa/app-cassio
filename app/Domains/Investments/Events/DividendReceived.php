<?php

namespace App\Domains\Investments\Events;

use App\Domains\Investments\Models\AssetDividend;
use Illuminate\Foundation\Events\Dispatchable;

class DividendReceived
{
    use Dispatchable;

    public function __construct(public AssetDividend $dividend)
    {
    }
}

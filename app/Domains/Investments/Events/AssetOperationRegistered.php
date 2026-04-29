<?php

namespace App\Domains\Investments\Events;

use App\Domains\Investments\Models\AssetOperation;
use Illuminate\Foundation\Events\Dispatchable;

class AssetOperationRegistered
{
    use Dispatchable;

    public function __construct(public AssetOperation $operation)
    {
    }
}

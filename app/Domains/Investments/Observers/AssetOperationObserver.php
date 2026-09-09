<?php

namespace App\Domains\Investments\Observers;

use App\Domains\Investments\Events\AssetOperationRegistered;
use App\Domains\Investments\Jobs\RecalculateAssetPositionJob;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Services\InvestmentBankSyncService;

class AssetOperationObserver
{
    public function created(AssetOperation $op): void
    {
        RecalculateAssetPositionJob::dispatchSync($op->asset_id);
        AssetOperationRegistered::dispatch($op);
    }

    public function updated(AssetOperation $op): void
    {
        RecalculateAssetPositionJob::dispatchSync($op->asset_id);
        app(InvestmentBankSyncService::class)->sync($op);
    }

    public function deleted(AssetOperation $op): void
    {
        $op->transactions()->delete();
        RecalculateAssetPositionJob::dispatchSync($op->asset_id);
    }
}

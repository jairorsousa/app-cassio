<?php

namespace App\Domains\Investments\Jobs;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Services\AssetPositionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateAssetPositionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $assetId)
    {
    }

    public function handle(AssetPositionService $service): void
    {
        $asset = Asset::find($this->assetId);
        if ($asset) {
            $service->recalculate($asset);
        }
    }
}

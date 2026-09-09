<?php

namespace App\Domains\Investments\Jobs;

use App\Domains\Investments\Services\RefreshAssetQuotesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshAssetQuotesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'investment-quotes';
    }

    public function handle(RefreshAssetQuotesService $service): void
    {
        $service->refresh();
    }
}

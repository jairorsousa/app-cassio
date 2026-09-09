<?php

namespace App\Domains\Investments\Observers;

use App\Domains\Investments\Events\DividendReceived;
use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Services\InvestmentBankSyncService;

class AssetDividendObserver
{
    public function updated(AssetDividend $div): void
    {
        app(InvestmentBankSyncService::class)->sync($div);
    }

    public function deleted(AssetDividend $div): void
    {
        $div->transactions()->delete();
    }

    public function created(AssetDividend $div): void
    {
        DividendReceived::dispatch($div);
    }
}

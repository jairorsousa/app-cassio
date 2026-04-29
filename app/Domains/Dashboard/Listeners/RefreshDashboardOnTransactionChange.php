<?php

namespace App\Domains\Dashboard\Listeners;

use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Dashboard\Services\DashboardSnapshotService;

class RefreshDashboardOnTransactionChange
{
    public function __construct(private DashboardSnapshotService $service)
    {
    }

    public function handle(): void
    {
        $this->service->invalidate();

        RefreshDashboardSnapshotJob::dispatch()->afterResponse();
    }
}

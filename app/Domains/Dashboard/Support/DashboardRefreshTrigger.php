<?php

namespace App\Domains\Dashboard\Support;

use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Dashboard\Services\DashboardSnapshotService;

class DashboardRefreshTrigger
{
    public function __construct(private DashboardSnapshotService $service)
    {
    }

    /**
     * Invalida cache do snapshot e agenda recálculo no fim da request.
     */
    public function __invoke(): void
    {
        $this->service->invalidate();
        RefreshDashboardSnapshotJob::dispatch()->afterResponse();
    }
}

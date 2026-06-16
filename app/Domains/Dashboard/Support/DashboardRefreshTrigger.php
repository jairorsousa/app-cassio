<?php

namespace App\Domains\Dashboard\Support;

use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        try {
            RefreshDashboardSnapshotJob::dispatch()->afterResponse();
        } catch (Throwable $exception) {
            Log::warning('Failed to schedule dashboard snapshot refresh.', [
                'exception' => $exception,
            ]);
        }
    }
}

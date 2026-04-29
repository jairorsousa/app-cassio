<?php

namespace App\Domains\Dashboard\Jobs;

use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshDashboardSnapshotJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'dashboard-snapshot';
    }

    public function handle(DashboardSnapshotService $service): void
    {
        $service->refresh();
    }
}

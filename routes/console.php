<?php

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Jobs\GenerateRecurringTransactionsJob;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new GenerateRecurringTransactionsJob)->dailyAt('00:05');
Schedule::job(new CloseInvoiceJob)->dailyAt('00:10');
Schedule::job(new RefreshDashboardSnapshotJob)->everyFifteenMinutes();

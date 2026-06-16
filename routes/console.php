<?php

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Jobs\GenerateRecurringTransactionsJob;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('writs:sync-google-calendar-cessions {--queue : Dispatch queue jobs instead of syncing immediately}', function (GoogleCalendarService $googleCalendar) {
    $writs = Writ::query()
        ->where('stage', 'pending')
        ->whereNotNull('cession_at')
        ->whereNull('google_calendar_event_id')
        ->orderBy('cession_at')
        ->get();

    if ($writs->isEmpty()) {
        $this->info('Nenhum requisitorio pendente de sincronizacao com Google Agenda.');

        return Command::SUCCESS;
    }

    if ($this->option('queue')) {
        $writs->each(fn (Writ $writ) => SyncWritCessionToGoogleCalendar::dispatch($writ->id));
        $this->info("{$writs->count()} job(s) enviados para a fila.");

        return Command::SUCCESS;
    }

    $synced = 0;
    $skipped = 0;

    foreach ($writs as $writ) {
        try {
            $event = $googleCalendar->syncWritCession($writ);

            if ($event) {
                $synced++;
                $this->line("Sincronizado requisitorio #{$writ->id}: {$event->getHtmlLink()}");
            } else {
                $skipped++;
                $this->warn("Nao sincronizado requisitorio #{$writ->id}: {$writ->fresh()->google_calendar_sync_error}");
            }
        } catch (Throwable $exception) {
            $skipped++;
            $this->error("Erro no requisitorio #{$writ->id}: {$exception->getMessage()}");
        }
    }

    $this->info("Concluido. Sincronizados: {$synced}. Nao sincronizados: {$skipped}.");

    return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Sync pending writ cession dates with Google Agenda');

Schedule::job(new GenerateRecurringTransactionsJob)->dailyAt('00:05');
Schedule::job(new CloseInvoiceJob)->dailyAt('00:10');
Schedule::job(new RefreshDashboardSnapshotJob)->everyFifteenMinutes();

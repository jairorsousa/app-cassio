<?php

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Jobs\GenerateRecurringTransactionsJob;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Integrations\Models\GoogleCalendarToken;
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

Artisan::command('google-calendar:status', function () {
    $token = GoogleCalendarToken::central();
    $pendingWrits = Writ::query()
        ->where('stage', 'pending')
        ->whereNotNull('cession_at')
        ->whereNull('google_calendar_event_id')
        ->count();

    $this->components->info('Google Calendar integration status');
    $this->table(
        ['Item', 'Value'],
        [
            ['Enabled', config('google-calendar.enabled') ? 'yes' : 'no'],
            ['Client ID configured', filled(config('google-calendar.client_id')) ? 'yes' : 'no'],
            ['Client Secret configured', filled(config('google-calendar.client_secret')) ? 'yes' : 'no'],
            ['Redirect URI', config('google-calendar.redirect_uri') ?: '-'],
            ['Calendar ID', config('google-calendar.calendar_id') ?: '-'],
            ['Timezone', config('google-calendar.timezone') ?: '-'],
            ['Connect URL', url('/google/calendar/connect')],
            ['Token saved', $token ? 'yes' : 'no'],
            ['Token calendar', $token?->calendar_id ?: '-'],
            ['Token connected at', $token?->connected_at?->toDateTimeString() ?: '-'],
            ['Token expires at', $token?->expires_at?->toDateTimeString() ?: '-'],
            ['Refresh token saved', $token?->refresh_token ? 'yes' : 'no'],
            ['Pending writs without event', (string) $pendingWrits],
        ],
    );

    if (! $token) {
        $this->warn('OAuth ainda nao foi concluido. Acesse a Connect URL logado no sistema e autorize a conta Google correta.');
    }

    return Command::SUCCESS;
})->purpose('Show Google Calendar integration status');

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

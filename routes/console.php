<?php

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Jobs\GenerateRecurringTransactionsJob;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
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
        ->whereNotNull('cession_at')
        ->whereNull('google_calendar_event_id')
        ->count();
    $pendingPetitions = Writ::query()
        ->whereNotNull('petitioned_at')
        ->whereNull('google_calendar_petition_event_id')
        ->count();
    $pendingAwaitingReceipts = Writ::query()
        ->whereNotNull('awaiting_receipt_at')
        ->whereNull('google_calendar_awaiting_receipt_event_id')
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
            ['Pending cessions without event', (string) $pendingWrits],
            ['Pending petitions without event', (string) $pendingPetitions],
            ['Pending awaiting receipts without event', (string) $pendingAwaitingReceipts],
        ],
    );

    if (! $token) {
        $this->warn('OAuth ainda nao foi concluido. Acesse a Connect URL logado no sistema e autorize a conta Google correta.');
    }

    return Command::SUCCESS;
})->purpose('Show Google Calendar integration status');

Artisan::command('writs:sync-google-calendar-cessions {--queue : Dispatch queue jobs instead of syncing immediately} {--all : Include writs that already have Google events and update them}', function (GoogleCalendarService $googleCalendar) {
    $query = Writ::query()
        ->whereNotNull('cession_at')
        ->orderBy('cession_at');

    if (! $this->option('all')) {
        $query->whereNull('google_calendar_event_id');
    }

    $writs = $query->get();

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

Artisan::command('writs:sync-google-calendar-petitions {--queue : Dispatch queue jobs instead of syncing immediately} {--all : Include writs that already have Google petition events and update them}', function (GoogleCalendarService $googleCalendar) {
    $query = Writ::query()
        ->whereNotNull('petitioned_at')
        ->orderBy('petitioned_at');

    if (! $this->option('all')) {
        $query->whereNull('google_calendar_petition_event_id');
    }

    $writs = $query->get();

    if ($writs->isEmpty()) {
        $this->info('Nenhum peticionamento pendente de sincronizacao com Google Agenda.');

        return Command::SUCCESS;
    }

    if ($this->option('queue')) {
        $writs->each(fn (Writ $writ) => SyncWritPetitionToGoogleCalendar::dispatch($writ->id));
        $this->info("{$writs->count()} job(s) de peticionamento enviados para a fila.");

        return Command::SUCCESS;
    }

    $synced = 0;
    $skipped = 0;

    foreach ($writs as $writ) {
        try {
            $event = $googleCalendar->syncWritPetition($writ);

            if ($event) {
                $synced++;
                $this->line("Sincronizado peticionamento do requisitorio #{$writ->id}: {$event->getHtmlLink()}");
            } else {
                $skipped++;
                $this->warn("Nao sincronizado peticionamento do requisitorio #{$writ->id}: {$writ->fresh()->google_calendar_petition_sync_error}");
            }
        } catch (Throwable $exception) {
            $skipped++;
            $this->error("Erro no peticionamento do requisitorio #{$writ->id}: {$exception->getMessage()}");
        }
    }

    $this->info("Concluido. Sincronizados: {$synced}. Nao sincronizados: {$skipped}.");

    return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Sync petitioning writ dates with Google Agenda');

Artisan::command('writs:sync-google-calendar-awaiting-receipts {--queue : Dispatch queue jobs instead of syncing immediately} {--all : Include writs that already have Google awaiting receipt events and update them}', function (GoogleCalendarService $googleCalendar) {
    $query = Writ::query()
        ->whereNotNull('awaiting_receipt_at')
        ->orderBy('awaiting_receipt_at');

    if (! $this->option('all')) {
        $query->whereNull('google_calendar_awaiting_receipt_event_id');
    }

    $writs = $query->get();

    if ($writs->isEmpty()) {
        $this->info('Nenhum aguardando recebimento pendente de sincronizacao com Google Agenda.');

        return Command::SUCCESS;
    }

    if ($this->option('queue')) {
        $writs->each(fn (Writ $writ) => SyncWritAwaitingReceiptToGoogleCalendar::dispatch($writ->id));
        $this->info("{$writs->count()} job(s) de aguardando recebimento enviados para a fila.");

        return Command::SUCCESS;
    }

    $synced = 0;
    $skipped = 0;

    foreach ($writs as $writ) {
        try {
            $event = $googleCalendar->syncWritAwaitingReceipt($writ);

            if ($event) {
                $synced++;
                $this->line("Sincronizado aguardando recebimento do requisitorio #{$writ->id}: {$event->getHtmlLink()}");
            } else {
                $skipped++;
                $this->warn("Nao sincronizado aguardando recebimento do requisitorio #{$writ->id}: {$writ->fresh()->google_calendar_awaiting_receipt_sync_error}");
            }
        } catch (Throwable $exception) {
            $skipped++;
            $this->error("Erro no aguardando recebimento do requisitorio #{$writ->id}: {$exception->getMessage()}");
        }
    }

    $this->info("Concluido. Sincronizados: {$synced}. Nao sincronizados: {$skipped}.");

    return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Sync awaiting receipt writ dates with Google Agenda');

Schedule::job(new GenerateRecurringTransactionsJob)->dailyAt('00:05');
Schedule::job(new CloseInvoiceJob)->dailyAt('00:10');
Schedule::job(new RefreshDashboardSnapshotJob)->everyFifteenMinutes();

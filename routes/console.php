<?php

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Jobs\GenerateRecurringTransactionsJob;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Integrations\Models\GoogleCalendarToken;
use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Investments\Jobs\RefreshAssetQuotesJob;
use App\Domains\Investments\Services\RefreshAssetQuotesService;
use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritMonitoringToGoogleCalendar;
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
    $pendingMonitoring = Writ::query()
        ->whereNotNull('monitoring_at')
        ->whereNull('google_calendar_monitoring_event_id')
        ->count();
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
            ['Pending monitoring without event', (string) $pendingMonitoring],
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

Artisan::command('writs:sync-google-calendar-monitoring {--queue : Dispatch queue jobs instead of syncing immediately} {--all : Include writs that already have Google monitoring events and update them}', function (GoogleCalendarService $googleCalendar) {
    $query = Writ::query()
        ->whereNotNull('monitoring_at')
        ->orderBy('monitoring_at');

    if (! $this->option('all')) {
        $query->whereNull('google_calendar_monitoring_event_id');
    }

    $writs = $query->get();

    if ($writs->isEmpty()) {
        $this->info('Nenhum monitoramento pendente de sincronizacao com Google Agenda.');

        return Command::SUCCESS;
    }

    if ($this->option('queue')) {
        $writs->each(fn (Writ $writ) => SyncWritMonitoringToGoogleCalendar::dispatch($writ->id));
        $this->info("{$writs->count()} job(s) de monitoramento enviados para a fila.");

        return Command::SUCCESS;
    }

    $synced = 0;
    $skipped = 0;

    foreach ($writs as $writ) {
        try {
            $event = $googleCalendar->syncWritMonitoring($writ);

            if ($event) {
                $synced++;
                $this->line("Sincronizado monitoramento do requisitorio #{$writ->id}: {$event->getHtmlLink()}");
            } else {
                $skipped++;
                $this->warn("Nao sincronizado monitoramento do requisitorio #{$writ->id}: {$writ->fresh()->google_calendar_monitoring_sync_error}");
            }
        } catch (Throwable $exception) {
            $skipped++;
            $this->error("Erro no monitoramento do requisitorio #{$writ->id}: {$exception->getMessage()}");
        }
    }

    $this->info("Concluido. Sincronizados: {$synced}. Nao sincronizados: {$skipped}.");

    return $skipped === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Sync monitoring writ dates with Google Agenda');

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

Artisan::command('writs:sync-google-calendar {writ}', function (Writ $writ, GoogleCalendarService $googleCalendar) {
    $rows = [];

    if ($writ->monitoring_at) {
        $event = $googleCalendar->syncWritMonitoring($writ);
        $rows[] = ['Monitorar processo', $event?->getHtmlLink() ?: ($writ->fresh()->google_calendar_monitoring_sync_error ?: 'falhou')];
    }

    if ($writ->cession_at) {
        $event = $googleCalendar->syncWritCession($writ);
        $rows[] = ['Cessao', $event?->getHtmlLink() ?: ($writ->fresh()->google_calendar_sync_error ?: 'falhou')];
    }

    if ($writ->petitioned_at) {
        $event = $googleCalendar->syncWritPetition($writ);
        $rows[] = ['Peticionar', $event?->getHtmlLink() ?: ($writ->fresh()->google_calendar_petition_sync_error ?: 'falhou')];
    }

    if ($writ->awaiting_receipt_at) {
        $event = $googleCalendar->syncWritAwaitingReceipt($writ);
        $rows[] = ['Aguardando recebimento', $event?->getHtmlLink() ?: ($writ->fresh()->google_calendar_awaiting_receipt_sync_error ?: 'falhou')];
    }

    if ($rows === []) {
        $this->warn("Requisitorio #{$writ->id} nao possui datas para sincronizar com o Google Calendar.");

        return Command::FAILURE;
    }

    $this->table(['Etapa', 'Resultado'], $rows);

    return Command::SUCCESS;
})->purpose('Sync a single writ with Google Calendar');

Artisan::command('investments:refresh-quotes', function (RefreshAssetQuotesService $service) {
    $result = $service->refresh();
    $this->info('Atualizadas: '.($result['updated'] === [] ? 'nenhuma' : implode(', ', $result['updated'])));
    if ($result['failed'] !== []) {
        $this->warn('Falhas: '.implode(', ', $result['failed']));
    }
    if ($result['skipped'] !== []) {
        $this->comment('Manuais: '.implode(', ', $result['skipped']));
    }

    return $result['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
})->purpose('Atualiza cotações de ativos listados em bolsa');

Schedule::job(new GenerateRecurringTransactionsJob)->dailyAt('00:05');
Schedule::job(new CloseInvoiceJob)->dailyAt('00:10');
Schedule::job(new RefreshDashboardSnapshotJob)->everyFifteenMinutes();
Schedule::job(new RefreshAssetQuotesJob)
    ->weekdays()
    ->dailyAt('18:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();
// Pregão: a cada 30 min, dias úteis 10:00–17:30 (Brasília). Plano gratuito brapi = cotação com atraso ~30 min.
Schedule::job(new RefreshAssetQuotesJob)
    ->name('investment-quotes-intraday')
    ->timezone('America/Sao_Paulo') // antes de between(): o intervalo usa o fuso definido no momento da chamada
    ->weekdays()
    ->everyThirtyMinutes()
    ->between('10:00', '17:30')
    ->withoutOverlapping();

Artisan::command('investments:statements-cleanup', function (\App\Domains\Investments\Services\Statements\BrokerStatementImportService $service) {
    $this->info('Lotes/arquivos limpos: '.$service->cleanup());
})->purpose('Apaga extratos de corretora expirados (TTL) e arquivos de lotes fechados');
Schedule::command('investments:statements-cleanup')->hourly();

Artisan::command('investments:statement-preview {file} {--broker=BTG Pactual}', function (\App\Domains\Investments\Services\Statements\BrokerStatementImportService $service) {
    // Prévia somente leitura: tudo dentro de transação revertida e arquivo temporário apagado.
    $file = (string) $this->argument('file');
    if (! is_readable($file)) {
        $this->error('Arquivo inexistente ou ilegível.');

        return 1;
    }
    $upload = new \Illuminate\Http\UploadedFile($file, basename($file), null, null, true);
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $imp = $service->stage($upload, (string) $this->option('broker'), null);
        $rows = $imp->rows()->get();
        $out = [
            'status' => $imp->status, 'text_status' => $imp->text_status, 'layout' => $imp->mapping['layout'] ?? null,
            'reconciliation' => $imp->summary['reconciliation'] ?? null, 'rows' => $rows->count(),
            'by_status' => $rows->countBy('status'),
            'by_kind' => $rows->groupBy('kind')->map(fn ($g) => ['count' => $g->count(), 'net' => round((float) $g->sum('net_amount'), 2), 'statuses' => $g->countBy('status')]),
            'importable_net' => round((float) $rows->where('status', 'ready')->sum('net_amount'), 2),
            'ready_rows' => $rows->where('status', 'ready')->map(fn ($r) => $r->date?->format('d/m/Y').' '.$r->kind.' '.($r->ticker ?? '?').' '.number_format((float) $r->net_amount, 2, ',', '.'))->values()->take(200),
            'duplicate_matches' => $rows->where('status', 'duplicate_candidate')->map(fn ($r) => $r->date?->format('d/m/Y').' '.$r->ticker.' '.number_format((float) $r->net_amount, 2, ',', '.').' ~ B3 '.$r->duplicateOf?->payment_date?->format('d/m/Y').' '.number_format((float) $r->duplicateOf?->net_amount, 2, ',', '.'))->values()->take(200),
            'duplicate_candidates_by_kind' => $rows->where('status', 'duplicate_candidate')->countBy('kind'),
            'errors' => $rows->where('status', 'error')->map(fn ($r) => $r->date?->format('d/m/Y').' '.$r->kind.' '.($r->ticker ?? '?').': '.implode(' ', $r->messages ?? []))->values(),
        ];
        if ($imp->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory(dirname($imp->file_path));
        }
    } finally {
        \Illuminate\Support\Facades\DB::rollBack();
    }
    $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info('Somente prévia: nada foi gravado.');

    return 0;
})->purpose('Prévia somente leitura de um extrato de corretora (sem gravar)');

Artisan::command('investments:account-csv-preview {files*}', function (\App\Domains\Investments\Services\Statements\TabularFileReader $reader, \App\Domains\Investments\Services\Statements\BankAccountCsvParser $parser) {
    // Somente leitura: não grava nada (nem staging). Saída só com agregados, sem descrições.
    $parsed = [];
    foreach ($this->argument('files') as $i => $file) {
        if (! is_readable($file)) {
            $this->error('Arquivo ilegível: '.basename($file));

            return 1;
        }
        $t = $reader->csv((string) file_get_contents($file));
        $parsed[] = $parser->parse($t['headers'], $t['rows'], 'arquivo'.($i + 1));
    }
    $c = $parser->combine($parsed);
    $this->line(json_encode([
        'files' => array_map(fn ($p) => array_merge($p['reconciliation'], ['file' => $p['rows'][0]['file'] ?? null]), $parsed),
        'combined' => ['from' => $c['from'], 'to' => $c['to'], 'rows' => count($c['rows']), 'duplicates_removed' => $c['duplicates_removed'], 'joins' => $c['joins'], 'by_kind' => $c['by_kind'], 'ok' => $c['ok']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info('Somente prévia: nada foi gravado.');

    return 0;
})->purpose('Prévia somente leitura de extratos de conta em CSV (saldo corrido, emenda entre arquivos)');

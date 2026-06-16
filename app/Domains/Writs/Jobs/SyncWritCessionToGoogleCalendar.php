<?php

namespace App\Domains\Writs\Jobs;

use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Writs\Models\Writ;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SyncWritCessionToGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $writId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $writ = Writ::find($this->writId);

        if (! $writ || $writ->stage !== 'pending' || ! $writ->cession_at) {
            return;
        }

        $googleCalendar->syncWritCession($writ);
    }

    public function failed(Throwable $exception): void
    {
        Writ::query()
            ->whereKey($this->writId)
            ->update([
                'google_calendar_sync_error' => Str::limit($exception->getMessage(), 2000, ''),
            ]);
    }
}

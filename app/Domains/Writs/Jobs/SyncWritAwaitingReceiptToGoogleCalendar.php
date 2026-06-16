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

class SyncWritAwaitingReceiptToGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $writId, public bool $forceNewEvent = false) {}

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

        if (! $writ || ! $writ->awaiting_receipt_at) {
            return;
        }

        $googleCalendar->syncWritAwaitingReceipt($writ, $this->forceNewEvent);
    }

    public function failed(Throwable $exception): void
    {
        Writ::query()
            ->whereKey($this->writId)
            ->update([
                'google_calendar_awaiting_receipt_sync_error' => Str::limit($exception->getMessage(), 2000, ''),
            ]);
    }
}

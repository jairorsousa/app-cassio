<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use Throwable;

class WritGoogleCalendarSyncDispatcher
{
    public function sync(Writ $writ): void
    {
        if ($writ->cession_at) {
            $this->syncCession($writ);
        }

        if ($writ->petitioned_at) {
            $this->syncPetition($writ);
        }

        if ($writ->awaiting_receipt_at) {
            $this->syncAwaitingReceipt($writ);
        }
    }

    public function syncCession(Writ $writ): bool
    {
        if (! $writ->cession_at) {
            return true;
        }

        if (! $this->dispatchSyncSafely(SyncWritCessionToGoogleCalendar::class, $writ->id)) {
            return false;
        }

        return blank($writ->fresh()->google_calendar_sync_error);
    }

    public function syncPetition(Writ $writ): bool
    {
        if (! $writ->petitioned_at) {
            return true;
        }

        if (! $this->dispatchSyncSafely(SyncWritPetitionToGoogleCalendar::class, $writ->id)) {
            return false;
        }

        return blank($writ->fresh()->google_calendar_petition_sync_error);
    }

    public function syncAwaitingReceipt(Writ $writ): bool
    {
        if (! $writ->awaiting_receipt_at) {
            return true;
        }

        if (! $this->dispatchSyncSafely(SyncWritAwaitingReceiptToGoogleCalendar::class, $writ->id)) {
            return false;
        }

        return blank($writ->fresh()->google_calendar_awaiting_receipt_sync_error);
    }

    /**
     * @param  class-string  $jobClass
     */
    private function dispatchSyncSafely(string $jobClass, int $writId): bool
    {
        try {
            $jobClass::dispatchSync($writId);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;

class WritGoogleCalendarSyncDispatcher
{
    public function sync(Writ $writ): void
    {
        if ($writ->cession_at) {
            $this->syncCession($writ);
        }

        if ($writ->petitioned_at) {
            SyncWritPetitionToGoogleCalendar::dispatchSync($writ->id);
        }

        if ($writ->awaiting_receipt_at) {
            $this->syncAwaitingReceipt($writ);
        }
    }

    public function syncCession(Writ $writ): void
    {
        if (! $writ->cession_at) {
            return;
        }

        SyncWritCessionToGoogleCalendar::dispatchSync($writ->id);
    }

    public function syncAwaitingReceipt(Writ $writ, bool $forceNewEvent = false): void
    {
        if (! $writ->awaiting_receipt_at) {
            return;
        }

        SyncWritAwaitingReceiptToGoogleCalendar::dispatchSync($writ->id, $forceNewEvent);
    }
}
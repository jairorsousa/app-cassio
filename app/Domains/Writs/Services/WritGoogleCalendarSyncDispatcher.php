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
            SyncWritCessionToGoogleCalendar::dispatchSync($writ->id);
        }

        if ($writ->petitioned_at) {
            SyncWritPetitionToGoogleCalendar::dispatchSync($writ->id);
        }

        if ($writ->awaiting_receipt_at) {
            SyncWritAwaitingReceiptToGoogleCalendar::dispatchSync($writ->id);
        }
    }
}
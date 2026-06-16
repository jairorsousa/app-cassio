<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritGoogleCalendarSyncDispatcher;
use App\Domains\Writs\Services\WritService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WritGoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_syncs_each_configured_stage_datetime(): void
    {
        Bus::fake();

        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'process_number' => '0001234-56.2026.8.13.0001',
            'face_value' => 10000,
            'negotiated_amount' => 10000,
            'proposed_amount' => 8000,
            'paid_amount' => 8000,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'discount_percentage' => 20,
            'estimated_receipt_amount' => 12000,
            'cession_at' => '2026-06-15 14:00:00',
            'petitioned_at' => '2026-06-22 15:00:00',
            'awaiting_receipt_at' => '2026-06-23 16:00:00',
        ]);

        app(WritGoogleCalendarSyncDispatcher::class)->sync($writ);

        Bus::assertDispatchedSync(SyncWritCessionToGoogleCalendar::class, fn (SyncWritCessionToGoogleCalendar $job): bool => $job->writId === $writ->id);
        Bus::assertDispatchedSync(SyncWritPetitionToGoogleCalendar::class, fn (SyncWritPetitionToGoogleCalendar $job): bool => $job->writId === $writ->id);
        Bus::assertDispatchedSync(SyncWritAwaitingReceiptToGoogleCalendar::class, fn (SyncWritAwaitingReceiptToGoogleCalendar $job): bool => $job->writId === $writ->id);
    }

    public function test_transitioning_past_petitioning_still_dispatches_petition_sync(): void
    {
        Bus::fake();

        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'paid',
            'process_number' => '0001234-56.2026.8.13.0001',
            'face_value' => 10000,
            'negotiated_amount' => 10000,
            'proposed_amount' => 8000,
            'paid_amount' => 8000,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'discount_percentage' => 20,
            'estimated_receipt_amount' => 12000,
            'paid_at' => now()->toDateString(),
        ]);

        $service = app(WritService::class);

        $service->transitionTo($writ, 'petitioning', [
            'petitioned_at' => '2026-06-22 15:00:00',
        ]);

        $service->transitionTo($writ->fresh(), 'awaiting_receipt', [
            'awaiting_receipt_at' => '2026-06-23 16:00:00',
        ]);

        Bus::assertDispatchedSync(SyncWritPetitionToGoogleCalendar::class, fn (SyncWritPetitionToGoogleCalendar $job): bool => $job->writId === $writ->id);
        Bus::assertDispatchedSync(SyncWritAwaitingReceiptToGoogleCalendar::class, fn (SyncWritAwaitingReceiptToGoogleCalendar $job): bool => $job->writId === $writ->id);
    }

    public function test_transitioning_past_awaiting_receipt_still_dispatches_awaiting_sync(): void
    {
        Bus::fake();

        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'petitioning',
            'process_number' => '0001234-56.2026.8.13.0001',
            'face_value' => 10000,
            'negotiated_amount' => 10000,
            'proposed_amount' => 8000,
            'paid_amount' => 8000,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'discount_percentage' => 20,
            'estimated_receipt_amount' => 12000,
            'petitioned_at' => '2026-06-22 15:00:00',
        ]);

        $service = app(WritService::class);

        $service->transitionTo($writ, 'awaiting_receipt', [
            'awaiting_receipt_at' => '2026-06-23 16:00:00',
        ]);

        $service->transitionTo($writ->fresh(), 'finalized', [
            'finalized_at' => '2026-10-01',
            'actual_receipt_amount' => 98000,
        ]);

        Bus::assertDispatchedSync(SyncWritAwaitingReceiptToGoogleCalendar::class, fn (SyncWritAwaitingReceiptToGoogleCalendar $job): bool => $job->writId === $writ->id);
    }
}
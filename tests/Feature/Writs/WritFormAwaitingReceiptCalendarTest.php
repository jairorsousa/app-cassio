<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritFormAwaitingReceiptCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function makeAwaitingReceiptWrit(): Writ
    {
        return Writ::create([
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
            'paid_at' => now()->toDateString(),
            'awaiting_receipt_at' => '2026-06-23 16:00:00',
            'google_calendar_awaiting_receipt_event_id' => 'existing-event-id',
        ]);
    }

    public function test_editing_awaiting_receipt_date_updates_calendar_event_and_records_history(): void
    {
        Bus::fake();

        $writ = $this->makeAwaitingReceiptWrit();
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('writs.form', ['writ' => $writ])
            ->set('awaiting_receipt_at', '2026-06-24T10:00')
            ->call('save')
            ->assertHasNoErrors();

        Bus::assertDispatchedSync(
            SyncWritAwaitingReceiptToGoogleCalendar::class,
            fn (SyncWritAwaitingReceiptToGoogleCalendar $job): bool => $job->writId === $writ->id,
        );

        $history = WritStageHistory::query()->where('writ_id', $writ->id)->latest('id')->first();

        $this->assertNotNull($history);
        $this->assertSame('awaiting_receipt', $history->from_stage);
        $this->assertSame('awaiting_receipt', $history->to_stage);
        $this->assertSame('Data de aguardar recebimento atualizada de: 23/06/2026 16:00 para: 24/06/2026 10:00', $history->notes);
        $this->assertSame($user->id, $history->user_id);
    }

    public function test_editing_awaiting_receipt_without_date_change_does_not_sync_calendar(): void
    {
        Bus::fake();

        $writ = $this->makeAwaitingReceiptWrit();

        $this->actingAs(User::factory()->create());

        Volt::test('writs.form', ['writ' => $writ])
            ->set('process_number', '0009999-99.2026.8.13.0001')
            ->call('save')
            ->assertHasNoErrors();

        Bus::assertNotDispatched(SyncWritAwaitingReceiptToGoogleCalendar::class);
        $this->assertSame(0, WritStageHistory::query()->where('writ_id', $writ->id)->count());
    }
}
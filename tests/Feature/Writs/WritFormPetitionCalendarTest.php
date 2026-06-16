<?php

namespace Tests\Feature\Writs;

use App\Domains\Integrations\Services\GoogleCalendarService;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class WritFormPetitionCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function makePetitioningWrit(): Writ
    {
        return Writ::create([
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
            'paid_at' => now()->toDateString(),
            'petitioned_at' => '2026-06-22 15:00:00',
            'google_calendar_petition_event_id' => 'existing-petition-event-id',
        ]);
    }

    public function test_editing_petition_date_updates_calendar_event_and_records_history(): void
    {
        Bus::fake();

        $writ = $this->makePetitioningWrit();
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('writs.form', ['writ' => $writ])
            ->set('petitioned_at', '2026-06-23T10:30')
            ->call('save')
            ->assertHasNoErrors();

        Bus::assertDispatchedSync(
            SyncWritPetitionToGoogleCalendar::class,
            fn (SyncWritPetitionToGoogleCalendar $job): bool => $job->writId === $writ->id,
        );

        $history = WritStageHistory::query()->where('writ_id', $writ->id)->latest('id')->first();

        $this->assertNotNull($history);
        $this->assertSame('petitioning', $history->from_stage);
        $this->assertSame('petitioning', $history->to_stage);
        $this->assertSame('Data do peticionamento atualizada de: 22/06/2026 15:00 para: 23/06/2026 10:30', $history->notes);
        $this->assertSame($user->id, $history->user_id);
    }

    public function test_editing_petitioning_writ_without_date_change_does_not_sync_calendar(): void
    {
        Bus::fake();

        $writ = $this->makePetitioningWrit();

        $this->actingAs(User::factory()->create());

        Volt::test('writs.form', ['writ' => $writ])
            ->set('process_number', '0009999-99.2026.8.13.0001')
            ->call('save')
            ->assertHasNoErrors();

        Bus::assertNotDispatched(SyncWritPetitionToGoogleCalendar::class);
        $this->assertSame(0, WritStageHistory::query()->where('writ_id', $writ->id)->count());
    }

    public function test_editing_petition_date_does_not_fail_when_calendar_sync_errors(): void
    {
        $writ = $this->makePetitioningWrit();

        $this->actingAs(User::factory()->create());

        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('syncWritPetition')
                ->once()
                ->andThrow(new RuntimeException('Google API error'));
        });

        Volt::test('writs.form', ['writ' => $writ])
            ->set('petitioned_at', '2026-06-23T10:30')
            ->call('save')
            ->assertHasNoErrors();

        $history = WritStageHistory::query()->where('writ_id', $writ->id)->latest('id')->first();

        $this->assertNotNull($history);
        $this->assertSame('Data do peticionamento atualizada de: 22/06/2026 15:00 para: 23/06/2026 10:30', $history->notes);
        $this->assertSame('2026-06-23 10:30:00', $writ->fresh()->petitioned_at->format('Y-m-d H:i:s'));
    }
}
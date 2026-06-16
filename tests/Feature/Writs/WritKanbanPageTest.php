<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Services\WritService;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritKanbanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_displays_awaiting_receipt_stage(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Writ::create([
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
            'petitioned_at' => now(),
        ]);

        Volt::test('writs.kanban')
            ->assertSee('Aguardando Recebimento')
            ->assertSee('Perdido')
            ->assertSeeHtml('grid-cols-7')
            ->assertSeeHtml('kanban-card flex')
            ->assertSeeHtml('w-1 shrink-0 bg-info');
    }

    public function test_creating_petitioning_writ_dispatches_google_calendar_sync(): void
    {
        Bus::fake();

        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->set('stage', 'petitioning')
            ->set('formType', 'rpv')
            ->set('face_value', '10000')
            ->set('negotiated_amount', '10000')
            ->set('proposed_amount', '8000')
            ->set('paid_amount', '8000')
            ->set('notary_expenses_amount', '0')
            ->set('other_expenses_amount', '0')
            ->set('estimated_receipt_amount', '12000')
            ->set('petitioned_at', '2026-06-22T15:00')
            ->call('saveWrit')
            ->assertHasNoErrors();

        $writ = Writ::query()->first();

        $this->assertNotNull($writ);
        $this->assertSame('petitioning', $writ->stage);
        $this->assertNotNull($writ->petitioned_at);

        Bus::assertDispatchedSync(SyncWritPetitionToGoogleCalendar::class, function (SyncWritPetitionToGoogleCalendar $job) use ($writ): bool {
            return $job->writId === $writ->id;
        });
    }

    public function test_transition_to_petitioning_dispatches_google_calendar_sync(): void
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

        app(WritService::class)->transitionTo($writ, 'petitioning', [
            'petitioned_at' => '2026-06-22 15:00:00',
        ]);

        Bus::assertDispatchedSync(SyncWritPetitionToGoogleCalendar::class, function (SyncWritPetitionToGoogleCalendar $job) use ($writ): bool {
            return $job->writId === $writ->id;
        });
    }

    public function test_creating_awaiting_receipt_writ_dispatches_google_calendar_sync(): void
    {
        Bus::fake();

        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->set('stage', 'awaiting_receipt')
            ->set('formType', 'rpv')
            ->set('face_value', '10000')
            ->set('negotiated_amount', '10000')
            ->set('proposed_amount', '8000')
            ->set('paid_amount', '8000')
            ->set('notary_expenses_amount', '0')
            ->set('other_expenses_amount', '0')
            ->set('estimated_receipt_amount', '12000')
            ->set('awaiting_receipt_at', '2026-06-23T16:00')
            ->call('saveWrit')
            ->assertHasNoErrors();

        $writ = Writ::query()->first();

        $this->assertNotNull($writ);
        $this->assertSame('awaiting_receipt', $writ->stage);
        $this->assertNotNull($writ->awaiting_receipt_at);

        Bus::assertDispatchedSync(SyncWritAwaitingReceiptToGoogleCalendar::class, function (SyncWritAwaitingReceiptToGoogleCalendar $job) use ($writ): bool {
            return $job->writId === $writ->id;
        });
    }
}

<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        Volt::test('writs.kanban')
            ->assertSee('Aguardando Recebimento')
            ->assertSee('Perdido')
            ->assertSeeHtml('grid-cols-7');
    }

    public function test_creating_awaiting_receipt_writ_dispatches_google_calendar_sync(): void
    {
        Queue::fake();

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

        Queue::assertPushed(SyncWritAwaitingReceiptToGoogleCalendar::class, function (SyncWritAwaitingReceiptToGoogleCalendar $job) use ($writ): bool {
            return $job->writId === $writ->id;
        });
    }
}

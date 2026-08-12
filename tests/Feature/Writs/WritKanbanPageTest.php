<?php

namespace Tests\Feature\Writs;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritMonitoringToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use App\Domains\Writs\Services\WritService;
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
            ->assertSee('Recebido')
            ->assertDontSee('Finalizar')
            ->assertSee('Perdido')
            ->assertSee('Monitorar Processo')
            ->assertSeeHtml('grid-cols-8')
            ->assertSeeHtml('min-w-[3040px]')
            ->assertSeeHtml('kanban-card flex')
            ->assertSeeHtml('w-1.5 shrink-0 bg-info');
    }

    public function test_finalized_card_uses_detailed_financial_and_date_layout(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'assignor_name' => 'Rosineide Souza da Silva',
            'process_number' => '0901037-10.2019.8.10.0131',
            'face_value' => 81832.82,
            'negotiated_amount' => 57282.97,
            'proposed_amount' => 33174.73,
            'paid_amount' => 33174.73,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'estimated_receipt_amount' => 68449.18,
            'actual_receipt_amount' => 68449.18,
            'paid_at' => '2023-09-26',
            'finalized_at' => '2026-07-01',
        ]);

        Volt::test('writs.kanban')
            ->assertSee('Rosineide Souza da Silva')
            ->assertSee('Parte negociada')
            ->assertSee('Investimento')
            ->assertSee('Pagamento')
            ->assertSee('26/09/2023')
            ->assertSee('Recebimento')
            ->assertSee('01/07/2026')
            ->assertSee('Recebido')
            ->assertSee('R$ 68.449,18');
    }

    public function test_summary_indicators_use_the_expected_stages_and_amounts(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        $amountsByStage = [
            'monitoring' => [100, 1000],
            'negotiation' => [200, 2000],
            'pending' => [300, 3000],
            'paid' => [400, 4000],
            'petitioning' => [500, 5000],
            'awaiting_receipt' => [600, 6000],
            'finalized' => [700, 7000],
            'lost' => [800, 8000],
        ];

        foreach ($amountsByStage as $stage => [$negotiatedAmount, $estimatedReceiptAmount]) {
            Writ::create([
                'type' => 'rpv',
                'stage' => $stage,
                'negotiated_amount' => $negotiatedAmount,
                'estimated_receipt_amount' => $estimatedReceiptAmount,
            ]);
        }

        Volt::test('writs.kanban')
            ->assertSeeHtml('lg:grid-cols-3')
            ->assertSeeInOrder([
                'Totais',
                'Total negociado',
                'R$ 2.200,00',
                'Total investido',
                'Em aberto',
                'Investimento em aberto',
                'Recebimento estimado',
                'R$ 29.000,00',
                'Lucro esperado',
                '0,00%',
                'R$ 29.000,00',
                'Recebido',
                'Investimento finalizado',
                'Total recebido',
                'Lucro líquido',
            ]);
    }

    public function test_expected_profit_indicator_uses_open_investment_as_its_percentage_base(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'paid_amount' => 137770,
            'estimated_receipt_amount' => 308000,
        ]);

        Volt::test('writs.kanban')
            ->assertSeeInOrder([
                'Lucro esperado',
                '123,56%',
                'R$ 170.230,00',
            ]);
    }

    public function test_investment_indicators_separate_open_and_finalized_amounts(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'paid_amount' => 137770,
            'estimated_receipt_amount' => 308000,
        ]);

        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'paid_amount' => 95000,
            'notary_expenses_amount' => 300,
            'other_expenses_amount' => 33.22,
            'actual_receipt_amount' => 152554.85,
        ]);

        Volt::test('writs.kanban')
            ->assertSeeInOrder([
                'Total investido',
                'R$ 233.103,22',
                'Investimento em aberto',
                'R$ 137.770,00',
                'Investimento finalizado',
                'R$ 95.333,22',
                'Total recebido',
                'R$ 152.554,85',
                'Lucro líquido',
                '60,02%',
                'R$ 57.221,63',
            ]);
    }

    public function test_summary_indicators_follow_the_kanban_filters(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'debtor_entity' => 'Município Alfa',
            'negotiated_amount' => 140,
            'paid_amount' => 100,
            'estimated_receipt_amount' => 160,
            'paid_at' => '2026-01-15',
            'awaiting_receipt_at' => '2026-03-15 10:00:00',
        ]);

        Writ::create([
            'type' => 'precatorio',
            'stage' => 'finalized',
            'debtor_entity' => 'Estado Beta',
            'negotiated_amount' => 250,
            'paid_amount' => 200,
            'notary_expenses_amount' => 10,
            'actual_receipt_amount' => 300,
            'paid_at' => '2026-02-15',
            'awaiting_receipt_at' => '2026-04-15 10:00:00',
            'finalized_at' => '2026-05-15',
        ]);

        $component = Volt::test('writs.kanban')
            ->set('type', 'rpv')
            ->assertSeeInOrder([
                'Total investido',
                'R$ 100,00',
                'Investimento em aberto',
                'R$ 100,00',
                'Recebimento estimado',
                'R$ 160,00',
                'Lucro esperado',
                '60,00%',
                'R$ 60,00',
            ])
            ->assertDontSee('R$ 300,00');

        $component
            ->call('clearFilters')
            ->set('debtor', 'Estado Beta')
            ->assertSeeInOrder([
                'Total investido',
                'R$ 210,00',
                'Investimento em aberto',
                'R$ 0,00',
                'Investimento finalizado',
                'R$ 210,00',
                'Total recebido',
                'R$ 300,00',
                '42,86%',
                'R$ 90,00',
            ])
            ->assertDontSee('R$ 160,00');

        $component
            ->call('clearFilters')
            ->set('from', '2026-02-01')
            ->set('to', '2026-02-28')
            ->assertSee('R$ 160,00')
            ->assertSee('R$ 300,00')
            ->set('dateFilter', 'payment')
            ->assertSee('R$ 300,00')
            ->assertDontSee('R$ 160,00');

        $component
            ->call('clearFilters')
            ->set('dateFilter', 'awaiting')
            ->set('from', '2026-03-01')
            ->set('to', '2026-03-31')
            ->assertSee('R$ 160,00')
            ->assertDontSee('R$ 300,00');

        $component
            ->call('clearFilters')
            ->set('dateFilter', 'receipt')
            ->set('from', '2026-05-01')
            ->set('to', '2026-05-31')
            ->assertSee('R$ 300,00')
            ->assertDontSee('R$ 160,00');
    }

    public function test_search_finds_writ_by_linked_assignor_name(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        $francisco = Contact::create([
            'name' => 'Francisco de Sousa',
            'status' => true,
        ]);
        $maria = Contact::create([
            'name' => 'Maria Oliveira',
            'status' => true,
        ]);

        $franciscoWrit = Writ::create([
            'type' => 'rpv',
            'stage' => 'negotiation',
            'process_number' => '0001111-11.2026.8.13.0001',
        ]);
        $mariaWrit = Writ::create([
            'type' => 'rpv',
            'stage' => 'negotiation',
            'process_number' => '0002222-22.2026.8.13.0001',
        ]);

        WritAssignor::create([
            'writ_id' => $franciscoWrit->id,
            'contact_id' => $francisco->id,
            'role' => 'parte',
        ]);
        WritAssignor::create([
            'writ_id' => $mariaWrit->id,
            'contact_id' => $maria->id,
            'role' => 'parte',
        ]);

        Volt::test('writs.kanban')
            ->set('debtor', 'Francisco')
            ->assertSee('Francisco de Sousa')
            ->assertSee('0001111-11.2026.8.13.0001')
            ->assertDontSee('Maria Oliveira')
            ->assertDontSee('0002222-22.2026.8.13.0001');
    }

    public function test_creating_monitoring_writ_dispatches_google_calendar_sync_without_values(): void
    {
        Bus::fake();

        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->set('stage', 'monitoring')
            ->set('formType', 'rpv')
            ->set('process_number', '0001234-56.2026.8.13.0001')
            ->set('monitoring_at', '2026-06-25T09:30')
            ->call('saveWrit')
            ->assertHasNoErrors();

        $writ = Writ::query()->first();

        $this->assertNotNull($writ);
        $this->assertSame('monitoring', $writ->stage);
        $this->assertEquals(0.0, (float) $writ->face_value);
        $this->assertEquals(0.0, (float) $writ->paid_amount);
        $this->assertSame('2026-06-25 09:30:00', $writ->monitoring_at->format('Y-m-d H:i:s'));

        Bus::assertDispatchedSync(SyncWritMonitoringToGoogleCalendar::class, function (SyncWritMonitoringToGoogleCalendar $job) use ($writ): bool {
            return $job->writId === $writ->id;
        });
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

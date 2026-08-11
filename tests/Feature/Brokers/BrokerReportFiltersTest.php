<?php

namespace Tests\Feature\Brokers;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BrokerReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Broker $firstBroker;

    private Broker $secondBroker;

    private CaseType $caseType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-11 10:00:00');

        $this->firstBroker = Broker::create(['name' => 'Ana Corretora']);
        $this->secondBroker = Broker::create(['name' => 'Bruno Corretor']);
        $this->caseType = CaseType::create(['name' => 'Previdenciário']);

        $this->createCommission($this->firstBroker, '2026-08-05', 100, 'paid');
        $this->createCommission($this->firstBroker, '2026-07-15', 200, 'paid');
        $this->createCommission($this->secondBroker, '2026-08-07', 300, 'pending');
        $this->createCommission($this->secondBroker, '2025-08-07', 400, 'paid');

        BrokerAdvance::create([
            'broker_id' => $this->secondBroker->id,
            'date' => '2026-08-08',
            'amount' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_defaults_to_current_month_and_current_year(): void
    {
        Volt::test('brokers.reports')
            ->assertSet('month', '8')
            ->assertSet('year', '2026')
            ->assertViewHas('totalCommissions', fn ($total) => (float) $total === 400.0)
            ->assertSee('2023')
            ->assertSee('2028');
    }

    public function test_month_year_and_broker_filters_apply_to_the_whole_report(): void
    {
        Volt::test('brokers.reports')
            ->set('month', 'all')
            ->set('year', '2025')
            ->assertViewHas('totalCommissions', fn ($total) => (float) $total === 400.0)
            ->set('year', '2026')
            ->set('broker_id', (string) $this->secondBroker->id)
            ->assertViewHas('totalCommissions', fn ($total) => (float) $total === 300.0)
            ->assertViewHas('totalAdvances', fn ($total) => (float) $total === 50.0)
            ->assertViewHas('brokers', fn ($brokers) => $brokers->count() === 1 && $brokers->first()->is($this->secondBroker));
    }

    public function test_custom_dates_override_month_and_year_until_they_are_cleared(): void
    {
        Volt::test('brokers.reports')
            ->set('start_date', '2026-07-01')
            ->set('end_date', '2026-07-31')
            ->assertViewHas('totalCommissions', fn ($total) => (float) $total === 200.0)
            ->call('clearCustomPeriod')
            ->assertSet('start_date', null)
            ->assertSet('end_date', null)
            ->assertViewHas('totalCommissions', fn ($total) => (float) $total === 400.0);
    }

    private function createCommission(Broker $broker, string $date, float $amount, string $status): void
    {
        BrokerCommission::create([
            'broker_id' => $broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => $amount,
            'percentage_applied' => 100,
            'commission_amount' => $amount,
            'status' => $status,
            'reference_date' => $date,
        ]);
    }
}

<?php

namespace Tests\Feature\Investments;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa8InvestmentsSummaryB3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->actingAs(User::factory()->create());
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $batch = InvestmentImportBatch::create(['source' => 'b3_midas', 'status' => 'confirmed', 'base_date' => '2026-10-30', 'confirmed_at' => now()]);
        $rows = [
            ['AAAA3', 100, 10, 1200, 'custodia', 100, 0],
            ['BBBB3', 50, null, 200, 'custodia', 50, 0],
            ['DDDD3', 1000, null, 4780, 'custodia+emprestimo_doador', null, null],
        ];
        foreach ($rows as [$t, $q, $pm, $mv, $o, $qc, $ql]) {
            $a = Asset::create(['ticker' => $t, 'name' => $t, 'asset_class_id' => $class->id]);
            B3PositionSnapshot::create(['import_batch_id' => $batch->id, 'asset_id' => $a->id, 'base_date' => '2026-10-30', 'ticker' => $t, 'origin' => $o, 'quantity' => $q, 'quantity_custody' => $qc, 'quantity_lent' => $ql, 'average_price' => $pm, 'average_price_status' => $pm ? 'confirmado' : 'nao_informado', 'close_price' => $mv / $q, 'market_value' => $mv, 'source_key' => "b3pos:2026-10-30:$t"]);
        }
        foreach ([['2026-10-01', 'dividend', 10], ['2024-01-01', 'jcp', 5], ['2026-09-01', 'lending_reimbursement', 2.5]] as $i => [$d, $type, $v]) {
            AssetIncomeEvent::create(['asset_id' => Asset::first()->id, 'import_batch_id' => $batch->id, 'ticker' => 'AAAA3', 'payment_date' => $d, 'type' => $type, 'net_amount' => $v, 'is_lending' => $type === 'lending_reimbursement', 'source' => 'b3_midas', 'source_key' => "k$i"]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_falls_back_to_b3_without_double_counting(): void
    {
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertSame('b3', $s['source']);
        $this->assertSame('2026-10-30', $s['base_date']->toDateString());
        $this->assertEquals(6180, $s['market_value']); // DDDD3 contado uma vez pela quantidade total
        $this->assertEquals(1000, $s['total_invested']); // só PM conhecido
        $this->assertEquals(200, $s['unrealized_pnl']);
        $this->assertSame(['BBBB3', 'DDDD3'], $s['unknown_average_price']);
        $this->assertSame(['DDDD3'], $s['custody_split_missing']);
        $this->assertEquals(17.5, $s['dividends_total']);
        $this->assertEquals(12.5, $s['dividends_12m']);
        $this->assertEquals(6180, $s['by_class']['Ações']['market_value']);
    }

    public function test_operations_positions_take_precedence_over_b3(): void
    {
        AssetOperation::create(['asset_id' => Asset::first()->id, 'date' => '2026-11-02', 'type' => 'buy', 'quantity' => 10, 'unit_price' => 10, 'fees' => 0, 'total' => 100]);
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertSame('operations', $s['source']);
        $this->assertEquals(100, $s['total_invested']);
        $this->assertEquals(17.5, $s['dividends_total']);
    }

    public function test_investments_page_shows_b3_totals_and_split_warning(): void
    {
        Volt::test('investments.dashboard')
            ->assertSee('R$ 6.180,00')->assertSee('posição B3 de 30/10/2026')->assertSee('R$ 17,50')
            ->assertSee('não separado no arquivo B3: DDDD3')->assertSee('PM não informado')
            ->assertDontSee('Comece a construir sua carteira');
        $this->get('/investments')->assertOk();
    }
}

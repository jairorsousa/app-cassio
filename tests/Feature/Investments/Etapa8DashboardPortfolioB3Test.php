<?php

namespace Tests\Feature\Investments;

use App\Domains\Dashboard\Services\DashboardSnapshotBuilder;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\AssetQuote;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\InvestmentAnalyticsService;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa8DashboardPortfolioB3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 15:00:00');
        $this->actingAs(User::factory()->create());
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $batch = InvestmentImportBatch::create(['source' => 'b3_midas', 'status' => 'confirmed', 'base_date' => '2026-10-30', 'confirmed_at' => now()]);
        foreach ([['AAAA3', 100, 10, 1200], ['BBBB3', 50, null, 200]] as [$t, $q, $pm, $mv]) {
            $a = Asset::create(['ticker' => $t, 'name' => $t, 'asset_class_id' => $class->id]);
            B3PositionSnapshot::create(['import_batch_id' => $batch->id, 'asset_id' => $a->id, 'base_date' => '2026-10-30', 'ticker' => $t, 'origin' => 'custodia', 'quantity' => $q, 'quantity_custody' => $q, 'quantity_lent' => 0, 'average_price' => $pm, 'average_price_status' => $pm ? 'confirmado' : 'nao_informado', 'close_price' => $mv / $q, 'market_value' => $mv, 'source_key' => "b3pos:$t"]);
        }
        AssetQuote::create(['asset_id' => Asset::where('ticker', 'AAAA3')->value('id'), 'date' => '2026-11-05', 'price' => 13, 'market_time' => '2026-11-05 17:30:00', 'source' => 'brapi']);
        AssetIncomeEvent::create(['ticker' => 'AAAA3', 'import_batch_id' => $batch->id, 'payment_date' => '2026-10-01', 'type' => 'dividend', 'net_amount' => 10, 'source' => 'b3_midas', 'source_key' => 'k1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_investments_match_investments_summary(): void
    {
        $summary = app(PortfolioProfitabilityService::class)->summary();
        $s = app(DashboardSnapshotBuilder::class)->build();
        $this->assertEquals(1500, $summary['market_value']); // 1300 (cotação) + 200 (fechamento B3)
        $this->assertEquals($summary['market_value'], $s['investments']['market_value']);
        $this->assertEquals($summary['total_invested'], $s['investments']['invested']);
        $this->assertEquals(1000, $s['investments']['invested']);
        $this->assertEquals(10, $s['investments']['dividends_12m']);
        $this->assertEquals(1500, $s['patrimony']['portfolio_market_value']);
        $this->assertSame('b3:2026-10-30', $s['patrimony']['portfolio_source']);
        $this->assertSame('b3', $s['investments']['source']);
        $this->assertEquals(1400, $s['investments']['b3_market_value']);
        $this->assertSame(1, $s['investments']['unknown_average_price_count']);
        $this->assertEquals(1500, collect($s['investments']['by_class'])->sum('market_value'));
        $this->assertNotEquals(0, $s['investments']['market_value']);
    }

    public function test_evolution_starts_at_b3_base_date_without_zero_months(): void
    {
        $rows = app(InvestmentAnalyticsService::class)->portfolioEvolution();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-30', $rows[0]['date']);
        $this->assertEquals(1400, $rows[0]['market_value']);
        $this->assertEquals(1500, $rows[1]['market_value']);
        $this->assertEquals(1000, $rows[1]['invested']);
    }

    public function test_investments_page_footer_describes_sources(): void
    {
        Volt::test('investments.dashboard')
            ->assertSee('Desde a posição B3 de 30/10/2026')->assertSeeText('Atual: R$ 1.500,00')
            ->assertSee('brapi (plano gratuito, atraso ~30 min)')->assertSee('não é tratado como zero')
            ->assertDontSee('Cotações informadas manualmente');
    }
}

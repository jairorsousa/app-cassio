<?php

namespace Tests\Feature;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PartialResultTest extends TestCase
{
    use RefreshDatabase;

    private function seedPortfolio(bool $allKnown): void
    {
        $cls = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $batch = InvestmentImportBatch::create(['source' => 'b3_midas', 'status' => 'confirmed', 'base_date' => '2026-10-30', 'confirmed_at' => now()]);
        foreach ([['AAAA3', 100, 10.0, 1500], ['BBBB3', 50, $allKnown ? 20.0 : null, 4000]] as [$t, $qty, $pm, $mv]) {
            $a = Asset::create(['ticker' => $t, 'name' => $t, 'asset_class_id' => $cls->id]);
            B3PositionSnapshot::create(['import_batch_id' => $batch->id, 'asset_id' => $a->id, 'ticker' => $t, 'base_date' => '2026-10-30', 'quantity' => $qty, 'origin' => 'custodia', 'quantity_custody' => $qty, 'quantity_lent' => 0, 'average_price' => $pm, 'average_price_status' => $pm ? 'confirmado' : 'nao_informado', 'close_price' => $mv / $qty, 'market_value' => $mv, 'source_key' => "b3pos:2026-10-30:$t"]);
        }
        AssetIncomeEvent::create(['ticker' => 'BBBB3', 'type' => 'dividend', 'payment_date' => '2026-05-01', 'net_amount' => 300, 'source' => 'b3_midas', 'source_key' => 'k1']);
    }

    public function test_unknown_pm_makes_result_partial_and_excludes_its_value(): void
    {
        $this->seedPortfolio(false);
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertTrue($s['result_partial']);
        $this->assertEquals(1000.0, $s['total_invested']);
        $this->assertEquals(1500.0, $s['known_cost_market_value']);
        $this->assertEquals(4000.0, $s['unknown_cost_market_value']);
        $this->assertEquals(500.0, $s['unrealized_pnl']);
        $this->assertEquals(800.0, $s['total_return']); // 500 capital com PM + 300 proventos; nunca 5500 - 1000

        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
        Volt::test('investments.dashboard')->assertSee('Resultado informado')->assertDontSee('Lucro total')->assertSee('não calculado')->assertSeeHtml('data-partial-result');
    }

    public function test_all_known_keeps_total_labels(): void
    {
        $this->seedPortfolio(true);
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertFalse($s['result_partial']);
        $this->assertEquals(0.0, $s['unknown_cost_market_value']);
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
        Volt::test('investments.dashboard')->assertSee('Lucro total')->assertDontSee('não calculado');
    }
}

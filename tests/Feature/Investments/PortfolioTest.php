<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\AssetPositionService;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Domains\Investments\Services\RealizedPnLCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    private function makeAsset(string $ticker = 'PETR4'): Asset
    {
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        return Asset::create([
            'ticker' => $ticker,
            'name' => 'Test',
            'asset_class_id' => $class->id,
        ]);
    }

    public function test_buy_creates_position_with_correct_average(): void
    {
        $asset = $this->makeAsset();

        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 30.00, 'fees' => 0,
            'total' => 3000,
        ]);
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-02-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 40.00, 'fees' => 0,
            'total' => 4000,
        ]);

        $position = AssetPosition::where('asset_id', $asset->id)->first();
        $this->assertNotNull($position);
        $this->assertEquals(200.0, (float) $position->quantity);
        $this->assertEquals(35.00, (float) $position->average_price);
        $this->assertEquals(7000.0, (float) $position->total_invested);
    }

    public function test_sell_calculates_realized_pnl_correctly(): void
    {
        $asset = $this->makeAsset();
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000,
        ]);
        $sell = AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-03-10', 'type' => 'sell',
            'quantity' => 50, 'unit_price' => 50, 'fees' => 0, 'total' => 2500,
        ]);

        $sell->refresh();
        $this->assertEquals(1000.0, (float) $sell->realized_pnl);

        $position = AssetPosition::where('asset_id', $asset->id)->first();
        $this->assertEquals(50.0, (float) $position->quantity);
        $this->assertEquals(30.00, (float) $position->average_price);
        $this->assertEquals(1500.0, (float) $position->total_invested);
        $this->assertEquals(1000.0, (float) $position->realized_pnl_total);
    }

    public function test_sell_total_position_zeros_out(): void
    {
        $asset = $this->makeAsset();
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 25, 'fees' => 0, 'total' => 2500,
        ]);
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-02-10', 'type' => 'sell',
            'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000,
        ]);

        $position = AssetPosition::where('asset_id', $asset->id)->first();
        $this->assertEquals(0.0, (float) $position->quantity);
        $this->assertEquals(0.0, (float) $position->total_invested);
        $this->assertEquals(500.0, (float) $position->realized_pnl_total);
    }

    public function test_operation_creates_polymorphic_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Corretora', 'initial_balance' => 10000]);
        $asset = $this->makeAsset();

        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-04-10', 'type' => 'buy',
            'quantity' => 10, 'unit_price' => 100, 'fees' => 5,
            'total' => 1005, 'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(8995.0, $account->fresh()->balance());
    }

    public function test_dividend_creates_income_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Corretora', 'initial_balance' => 0]);
        $asset = $this->makeAsset('HGLG11');

        AssetDividend::create([
            'asset_id' => $asset->id,
            'payment_date' => '2026-04-15',
            'type' => 'fii',
            'unit_amount' => 1.20,
            'quantity' => 100,
            'total' => 120.00,
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(120.0, $account->fresh()->balance());
    }

    public function test_quote_update_changes_market_value(): void
    {
        $asset = $this->makeAsset();
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000,
        ]);

        app(AssetPositionService::class)->setQuote($asset, '2026-04-28', 35.00);

        $position = AssetPosition::where('asset_id', $asset->id)->first();
        $this->assertEquals(35.00, (float) $position->current_price);
        $this->assertEquals(3500.0, $position->marketValue());
        $this->assertEquals(500.0, $position->unrealizedPnL());
    }

    public function test_portfolio_summary_aggregates_classes(): void
    {
        $stocks = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $fiis = AssetClass::create(['name' => 'FIIs', 'slug' => 'fiis']);

        $petr = Asset::create(['ticker' => 'PETR4', 'name' => 'Petrobras', 'asset_class_id' => $stocks->id]);
        $hglg = Asset::create(['ticker' => 'HGLG11', 'name' => 'CSHG Logística', 'asset_class_id' => $fiis->id]);

        AssetOperation::create(['asset_id' => $petr->id, 'date' => '2026-01-10', 'type' => 'buy', 'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000]);
        AssetOperation::create(['asset_id' => $hglg->id, 'date' => '2026-01-10', 'type' => 'buy', 'quantity' => 50, 'unit_price' => 100, 'fees' => 0, 'total' => 5000]);

        $summary = app(PortfolioProfitabilityService::class)->summary();

        $this->assertEquals(8000.0, $summary['total_invested']);
        $this->assertCount(2, $summary['by_class']);
        $this->assertEquals(3000.0, $summary['by_class']['Ações']['invested']);
        $this->assertEquals(5000.0, $summary['by_class']['FIIs']['invested']);
    }

    public function test_realized_pnl_filters_by_period(): void
    {
        $asset = $this->makeAsset();
        AssetOperation::create(['asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy', 'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000]);
        AssetOperation::create(['asset_id' => $asset->id, 'date' => '2026-03-10', 'type' => 'sell', 'quantity' => 50, 'unit_price' => 50, 'fees' => 0, 'total' => 2500]);

        $within = app(RealizedPnLCalculator::class)->forPeriod(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));
        $outside = app(RealizedPnLCalculator::class)->forPeriod(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'));

        $this->assertEquals(1000.0, $within['total']);
        $this->assertEquals(0.0, $outside['total']);
    }
}

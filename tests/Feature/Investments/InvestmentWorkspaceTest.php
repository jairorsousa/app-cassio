<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Services\AssetPositionService;
use App\Domains\Investments\Services\InvestmentAnalyticsService;
use App\Domains\Investments\Services\InvestmentLedgerService;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvestmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 9)->startOfDay());
        $this->actingAs(User::factory()->create());
    }

    private function asset(string $ticker = 'TEST'): Asset
    {
        $class = AssetClass::firstOrCreate(['slug' => 'acoes'], ['name' => 'Ações']);

        return Asset::create(['ticker' => $ticker, 'name' => 'Ativo de teste', 'asset_class_id' => $class->id]);
    }

    private function operation(Asset $asset, array $extra = []): AssetOperation
    {
        return app(InvestmentLedgerService::class)->saveOperation(array_replace([
            'asset_id' => $asset->id, 'date' => '2026-08-01', 'type' => 'buy', 'quantity' => 10, 'unit_price' => 10, 'fees' => 0, 'total' => 100, 'bank_account_id' => null,
        ], $extra));
    }

    public function test_all_pages_render_empty_and_with_real_positions(): void
    {
        $pages = ['dashboard', 'assets.index', 'operations.index', 'dividends.index', 'positions', 'reports.profitability'];
        foreach ($pages as $page) {
            Volt::test('investments.'.$page)->assertSee('Visão geral');
        }
        $asset = $this->asset();
        $this->operation($asset);
        foreach ($pages as $page) {
            Volt::test('investments.'.$page)->assertSee('Visão geral');
        }
        Volt::test('investments.dashboard')->assertSee('TEST')->assertSee('Evolução do patrimônio');
    }

    public function test_asset_modal_can_create_custom_class_and_custody_details(): void
    {
        Volt::test('investments.assets.index')->call('create')
            ->set('ticker', 'cdb-2027')->set('name', 'CDB Banco')
            ->set('newClass', 'Renda fixa')->set('institution', 'Banco')
            ->set('maturity_date', '2027-09-01')->set('liquidity', 'Diária')
            ->call('save')->assertHasNoErrors()->assertSet('showFormModal', false);
        $this->assertDatabaseHas('assets', ['ticker' => 'CDB-2027', 'institution' => 'Banco', 'liquidity' => 'Diária']);
        $this->assertDatabaseHas('asset_classes', ['slug' => 'renda-fixa', 'name' => 'Renda Fixa']);
    }

    public function test_purchase_form_lists_asset_types_and_creates_unlisted_asset(): void
    {
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 5000]);

        $component = Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy');
        $component->assertSee('Nome')->assertSee('Setor')->assertSee('Preço unitário');
        foreach ([
            'Ações', 'FIIs', 'Stocks', 'BDRs', 'ETFs', 'ETFs Internacionais', 'REITs',
            'Criptomoedas', 'Renda Fixa', 'Tesouro Direto', 'Fundos de Investimentos', 'Outros',
        ] as $label) {
            $component->assertSee($label);
        }

        $crypto = AssetClass::where('slug', 'criptomoedas')->firstOrFail();

        $component->set('asset_class_id', $crypto->id)
            ->set('ticker', 'BTC')
            ->assertSet('willCreateAsset', true)
            ->set('quantity', '0.5')->set('unit_price', '1000')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assets', [
            'ticker' => 'BTC',
            'asset_class_id' => $crypto->id,
        ]);
        $this->assertSame(1, AssetOperation::count());
    }

    public function test_operation_modal_starts_with_buy_or_sell_and_requires_investment_account(): void
    {
        $asset = $this->asset();

        Volt::test('investments.operations.index')
            ->assertSee('Vincule uma corretora para começar');

        $checking = BankAccount::create(['name' => 'Nubank', 'type' => 'checking', 'initial_balance' => 1000]);
        $xp = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'bank' => 'XP', 'initial_balance' => 1000]);

        Volt::test('investments.operations.index')
            ->assertSee('XP Investimentos')
            ->assertDontSee('Nubank')
            ->assertDontSee('Vincule uma corretora para começar');

        Volt::test('investments.operations.index')
            ->call('create')
            ->assertSet('formStep', 'choose')
            ->assertSet('bank_account_id', $xp->id)
            ->assertSee('O que você deseja registrar?')
            ->call('chooseType', 'buy')
            ->assertSet('formStep', 'form')
            ->assertSet('opType', 'buy')
            ->assertSee('Nova compra')
            ->set('bank_account_id', null)
            ->set('ticker', 'TEST')
            ->assertSet('asset_id', $asset->id)
            ->set('quantity', '10')
            ->set('unit_price', '10')
            ->call('save')
            ->assertHasErrors('bank_account_id')
            ->set('bank_account_id', $checking->id)
            ->call('save')
            ->assertHasErrors('bank_account_id')
            ->set('bank_account_id', $xp->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertEquals(900, $xp->fresh()->balance());
        $this->assertEquals(1000, $checking->fresh()->balance());
    }

    public function test_operation_modal_integrates_with_bank_and_edit_and_delete_stay_in_sync(): void
    {
        $asset = $this->asset();
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 1000]);
        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', $asset->ticker)
            ->set('quantity', '10')->set('unit_price', '10')->set('bank_account_id', $bank->id)
            ->call('save')->assertHasNoErrors()->assertSet('showFormModal', false);
        $operation = AssetOperation::firstOrFail();
        $this->assertEquals(900, $bank->fresh()->balance());
        Volt::test('investments.operations.index')->call('edit', $operation->id)->set('unit_price', '20')->call('save')->assertHasNoErrors();
        $this->assertEquals(800, $bank->fresh()->balance());
        Volt::test('investments.operations.index')->call('delete', $operation->id)->assertHasNoErrors();
        $this->assertEquals(1000, $bank->fresh()->balance());
        $this->assertEquals(0, $asset->fresh()->position->quantity);
    }

    public function test_sale_accepts_only_assets_already_in_the_portfolio(): void
    {
        $held = $this->asset('PETR4');
        $this->asset('VALE3');
        $this->operation($held);
        $bank = BankAccount::create(['name' => 'BTG', 'type' => 'investment', 'initial_balance' => 5000]);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'sell')
            ->set('ticker', 'VALE3')
            ->assertHasErrors('ticker')
            ->assertSet('asset_id', null)
            ->set('quantity', '1')->set('unit_price', '10')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasErrors();

        $this->assertSame(0, AssetOperation::where('type', 'sell')->count());

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'sell')
            ->call('selectHolding', $held->id)
            ->assertSet('asset_id', $held->id)
            ->assertSet('ticker', 'PETR4')
            ->set('quantity', '4')->set('unit_price', '12')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(6, $held->fresh()->position->quantity);
        $this->assertEquals(5048, $bank->fresh()->balance());
    }

    public function test_purchase_creates_unregistered_b3_asset_from_ticker_lookup(): void
    {
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 10000]);
        Http::fake([
            'brapi.dev/api/quote/list*' => Http::response([
                'stocks' => [[
                    'stock' => 'PETR4',
                    'name' => 'PETROLEO BRASILEIRO S.A. PETROBRAS',
                    'sector' => 'Energy Minerals',
                    'subsector' => 'Petróleo e Gás Integrado',
                    'close' => 37.1,
                    'type' => 'stock',
                    'subType' => 'stock',
                ]],
            ]),
            'brapi.dev/api/v2/stocks/quote*' => Http::response([
                'results' => [[
                    'symbol' => 'PETR4',
                    'data' => [
                        'regularMarketPrice' => 38.42,
                        'regularMarketTime' => '2026-09-09T20:45:30.000Z',
                    ],
                ]],
            ]),
        ]);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', 'petr4')
            ->assertSet('willCreateAsset', true)
            ->assertSet('ticker', 'PETR4')
            ->assertSet('assetName', 'PETROLEO BRASILEIRO S.A. PETROBRAS')
            ->assertSet('assetSector', 'Petróleo e Gás Integrado')
            ->assertSet('unit_price', '38.42')
            ->assertSet('priceFromMarket', true)
            ->set('quantity', '10')->set('unit_price', '30')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assets', [
            'ticker' => 'PETR4',
            'name' => 'PETROLEO BRASILEIRO S.A. PETROBRAS',
            'sector' => 'Petróleo e Gás Integrado',
        ]);
        $this->assertSame(1, AssetOperation::count());
        $this->assertEquals(30.0, (float) AssetOperation::first()?->unit_price);
        $this->assertEquals(10, Asset::where('ticker', 'PETR4')->first()?->position?->quantity);
    }

    public function test_purchase_reuses_existing_ticker_and_restores_soft_deleted_asset(): void
    {
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 20000]);
        $asset = $this->asset('PETR4');

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', 'petr4')
            ->assertSet('asset_id', $asset->id)
            ->assertSet('willCreateAsset', false)
            ->set('quantity', '5')->set('unit_price', '20')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Asset::count());
        $this->assertEquals(5, $asset->fresh()->position?->quantity);

        $asset->delete();
        $this->assertSoftDeleted('assets', ['ticker' => 'PETR4']);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', 'PETR4')
            ->set('quantity', '3')->set('unit_price', '22')->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Asset::count());
        $this->assertNotSoftDeleted('assets', ['ticker' => 'PETR4']);
        $this->assertEquals(8, $asset->fresh()->position?->quantity);
        $this->assertSame(2, AssetOperation::count());
    }

    public function test_purchase_accepts_masked_unit_price_and_rejects_huge_values(): void
    {
        $asset = $this->asset();
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 100000]);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', $asset->ticker)
            ->set('quantity', '2')
            ->set('unit_price', '1.234,56')
            ->set('fees', '10,00')
            ->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $operation = AssetOperation::firstOrFail();
        $this->assertEquals(1234.56, (float) $operation->unit_price);
        $this->assertEquals(10.0, (float) $operation->fees);
        $this->assertEquals(2479.12, (float) $operation->total);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', $asset->ticker)
            ->set('quantity', '1')
            ->set('unit_price', '99999999999999999')
            ->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasErrors('unit_price');
    }

    public function test_purchase_allows_zero_or_empty_fees(): void
    {
        $asset = $this->asset();
        $bank = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 10000]);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', $asset->ticker)
            ->set('quantity', '1')
            ->set('unit_price', '10')
            ->set('fees', '0,00')
            ->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0.0, (float) AssetOperation::firstOrFail()->fees);
        $this->assertEquals(10.0, (float) AssetOperation::firstOrFail()->total);

        Volt::test('investments.operations.index')->call('create')->call('chooseType', 'buy')
            ->set('ticker', $asset->ticker)
            ->set('quantity', '1')
            ->set('unit_price', '10')
            ->set('fees', '')
            ->set('bank_account_id', $bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0.0, (float) AssetOperation::orderByDesc('id')->firstOrFail()->fees);
    }

    public function test_sale_cannot_precede_purchase_and_changes_are_rolled_back(): void
    {
        $asset = $this->asset();
        $this->operation($asset);
        try {
            $this->operation($asset, ['type' => 'sell', 'date' => '2026-07-31']);
            $this->fail('Should reject chronological overselling');
        } catch (ValidationException) {
            $this->assertSame(1, AssetOperation::count());
            $this->assertEquals(10, $asset->fresh()->position->quantity);
        }
    }

    public function test_purchase_cannot_be_deleted_if_later_sale_needs_it(): void
    {
        $asset = $this->asset();
        $buy = $this->operation($asset);
        $this->operation($asset, ['type' => 'sell', 'date' => '2026-08-02']);
        try {
            app(InvestmentLedgerService::class)->deleteOperation($buy->id);
            $this->fail('Should reject orphan sale');
        } catch (ValidationException) {
            $this->assertNotNull($buy->fresh());
        }
    }

    public function test_moving_operation_to_another_asset_recalculates_both(): void
    {
        $a = $this->asset('AAA');
        $b = $this->asset('BBB');
        $op = $this->operation($a);
        app(InvestmentLedgerService::class)->saveOperation([
            'asset_id' => $b->id, 'date' => '2026-08-01', 'type' => 'buy', 'quantity' => 10, 'unit_price' => 10, 'fees' => 0, 'total' => 100,
        ], $op->id);
        $this->assertEquals(0, $a->fresh()->position->quantity);
        $this->assertEquals(10, $b->fresh()->position->quantity);
    }

    public function test_dividend_edit_delete_and_period_reporting(): void
    {
        $asset = $this->asset();
        $bank = BankAccount::create(['name' => 'Conta']);
        Volt::test('investments.dividends.index')->call('create')->set('asset_id', $asset->id)
            ->set('payment_date', '2026-08-10')->set('quantity', '10')->set('unit_amount', '2')
            ->set('bank_account_id', $bank->id)->call('save')->assertHasNoErrors();
        $div = AssetDividend::firstOrFail();
        Volt::test('investments.dividends.index')->call('edit', $div->id)->set('unit_amount', '3')->call('save')->assertHasNoErrors();
        $this->assertEquals(30, $bank->fresh()->balance());
        $this->assertEquals(30, app(InvestmentAnalyticsService::class)->period('2026-08-01', '2026-08-31')['income']);
        $this->assertEquals(0, app(InvestmentAnalyticsService::class)->period('2026-09-01', '2026-09-09')['income']);
        Volt::test('investments.dividends.index')->call('delete', $div->id);
        $this->assertEquals(0, $bank->fresh()->balance());
    }

    public function test_summary_keeps_closed_position_profit_and_old_quote_does_not_replace_latest(): void
    {
        $asset = $this->asset();
        $this->operation($asset);
        $service = app(AssetPositionService::class);
        $service->setQuote($asset, '2026-09-01', 20);
        $service->setQuote($asset, '2026-08-01', 15);
        $this->assertEquals(20, $asset->fresh()->position->current_price);
        $this->operation($asset, ['type' => 'sell', 'date' => '2026-09-01', 'unit_price' => 20, 'total' => 200]);
        $summary = app(PortfolioProfitabilityService::class)->summary();
        $this->assertEquals(100, $summary['realized_pnl_total']);
        $this->assertEquals(100, $summary['total_return']);
        $this->assertEquals(0, $summary['market_value']);
    }

    public function test_report_validates_dates_and_exports_csv(): void
    {
        Volt::test('investments.reports.profitability')->set('from', '2026-09-09')->set('to', '2026-08-01')->call('applyFilters')->assertHasErrors('to');
        Volt::test('investments.reports.profitability')->call('export')->assertFileDownloaded('investimentos-2026-01-01-2026-09-09.csv');
    }

    public function test_portfolio_evolution_uses_cost_until_quotes_are_available(): void
    {
        $asset = $this->asset();
        $this->operation($asset, ['date' => '2026-06-10']);

        $rows = collect(app(InvestmentAnalyticsService::class)->portfolioEvolution())->keyBy('key');
        $this->assertEquals(0.0, $rows['2026-05']['market_value']);
        $this->assertEquals(100.0, $rows['2026-06']['market_value']);
        $this->assertEquals(100.0, $rows['2026-06']['invested']);

        app(AssetPositionService::class)->setQuote($asset, '2026-07-15', 20);

        $rows = collect(app(InvestmentAnalyticsService::class)->portfolioEvolution())->keyBy('key');
        $this->assertEquals(100.0, $rows['2026-06']['market_value']);
        $this->assertEquals(200.0, $rows['2026-07']['market_value']);
        $this->assertEquals(100.0, $rows['2026-07']['invested']);
        $this->assertEquals(200.0, $rows['2026-09']['market_value']);
    }

    public function test_receipts_on_last_day_are_included_in_charts_summary_and_reports(): void
    {
        $asset = $this->asset();
        AssetDividend::create(['asset_id' => $asset->id, 'payment_date' => '2026-09-09', 'type' => 'dividend', 'quantity' => 10, 'unit_amount' => 2, 'total' => 20]);
        $analytics = app(InvestmentAnalyticsService::class);
        $this->assertEquals(20, $analytics->period('2026-09-09', '2026-09-09')['income']);
        $this->assertEquals(20, collect($analytics->monthlyCashflow())->last()['income']);
        $this->assertEquals(20, app(PortfolioProfitabilityService::class)->summary()['dividends_12m']);
    }
}

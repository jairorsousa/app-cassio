<?php

namespace Tests\Feature\Investments;

use App\Domains\Investments\Jobs\RefreshAssetQuotesJob;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetQuote;
use App\Domains\Investments\Services\AssetPositionService;
use App\Domains\Investments\Services\RefreshAssetQuotesService;
use App\Domains\Investments\Support\MarketTicker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuoteRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 9)->setTime(15, 0));
        $this->actingAs(User::factory()->create());
    }

    public function test_market_ticker_recognizes_b3_codes_only(): void
    {
        $this->assertTrue(MarketTicker::isListed('PETR4'));
        $this->assertTrue(MarketTicker::isListed('hglg11'));
        $this->assertTrue(MarketTicker::isListed('BOVA11'));
        $this->assertFalse(MarketTicker::isListed('CDB-2027'));
        $this->assertFalse(MarketTicker::isListed('TESOURO'));
        $this->assertFalse(MarketTicker::isListed('PETR4F'));
        $this->assertFalse(MarketTicker::isListed(''));
        $this->assertSame('acoes', MarketTicker::classSlug('stock', 'stock'));
        $this->assertSame('acoes', MarketTicker::classSlug('stock', 'unit'));
        $this->assertSame('fiis', MarketTicker::classSlug('fund', 'fii'));
        $this->assertSame('etfs', MarketTicker::classSlug('fund', 'etf'));
        $this->assertSame('bdrs', MarketTicker::classSlug('bdr', 'bdr'));
    }

    public function test_refresh_updates_listed_open_positions_and_skips_the_rest(): void
    {
        $petr = $this->listedAsset('PETR4');
        $fii = $this->listedAsset('HGLG11');
        $cdb = $this->unlistedAsset('CDB-2027');
        $closed = $this->listedAsset('VALE3');

        $this->buy($petr, 100, 30);
        $this->buy($fii, 10, 150);
        $this->buy($cdb, 1, 1000);
        $this->buy($closed, 10, 50);
        AssetOperation::create([
            'asset_id' => $closed->id, 'date' => '2026-08-15', 'type' => 'sell',
            'quantity' => 10, 'unit_price' => 55, 'fees' => 0, 'total' => 550,
        ]);

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $symbol = strtoupper((string) ($query['symbols'] ?? ''));
            $prices = ['PETR4' => 48.42, 'HGLG11' => 160.1];
            if (! isset($prices[$symbol])) {
                return Http::response(['error' => true], 404);
            }

            return Http::response([
                'results' => [[
                    'symbol' => $symbol,
                    'data' => [
                        'regularMarketPrice' => $prices[$symbol],
                        'regularMarketTime' => '2026-09-09T20:45:30.000Z',
                    ],
                ]],
            ]);
        });

        $result = app(RefreshAssetQuotesService::class)->refresh();

        $this->assertSame(['HGLG11', 'PETR4'], $result['updated']);
        $this->assertSame(['CDB-2027'], $result['skipped']);
        $this->assertSame([], $result['failed']);
        $this->assertEquals(48.42, (float) $petr->fresh()->position->current_price);
        $this->assertEquals(160.1, (float) $fii->fresh()->position->current_price);
        $this->assertEquals(1000.0, (float) $cdb->fresh()->position->current_price);
        $this->assertEquals(0.0, (float) $closed->fresh()->position->quantity);
        $quote = $petr->fresh()->quotes->first();
        $this->assertNotNull($quote);
        $this->assertEquals('2026-09-09', $quote->date->format('Y-m-d'));
        $this->assertEquals(48.42, (float) $quote->price);
        Http::assertSentCount(2);
    }

    public function test_api_failure_keeps_previous_quote(): void
    {
        $asset = $this->listedAsset('PETR4');
        $this->buy($asset, 100, 30);
        app(AssetPositionService::class)->setQuote($asset, '2026-09-01', 35);

        Http::fake([
            'brapi.dev/*' => Http::response(['error' => true], 500),
        ]);

        $result = app(RefreshAssetQuotesService::class)->refresh();

        $this->assertSame(['PETR4'], $result['failed']);
        $this->assertEquals(35.0, (float) $asset->fresh()->position->current_price);
        $this->assertEquals(1, AssetQuote::count());
    }

    public function test_portfolio_button_refreshes_quotes(): void
    {
        $asset = $this->listedAsset('PETR4');
        $this->buy($asset, 100, 30);

        Http::fake([
            'brapi.dev/*' => Http::response([
                'results' => [[
                    'symbol' => 'PETR4',
                    'data' => [
                        'regularMarketPrice' => 41.5,
                        'regularMarketTime' => '2026-09-09T20:45:30.000Z',
                    ],
                ]],
            ]),
        ]);

        Volt::test('investments.positions')
            ->call('refreshQuotes')
            ->assertHasNoErrors()
            ->assertSee('1 cotação atualizada (PETR4).');

        $this->assertEquals(41.5, (float) $asset->fresh()->position->current_price);
    }

    public function test_job_refreshes_listed_quotes(): void
    {
        $asset = $this->listedAsset('PETR4');
        $this->buy($asset, 10, 20);
        $this->fakeQuote(22.25);

        (new RefreshAssetQuotesJob)->handle(app(RefreshAssetQuotesService::class));

        $this->assertEquals(22.25, (float) $asset->fresh()->position->current_price);
    }

    public function test_artisan_command_refreshes_listed_quotes(): void
    {
        $asset = $this->listedAsset('PETR4');
        $this->buy($asset, 10, 20);
        $this->fakeQuote(23);

        $this->artisan('investments:refresh-quotes')
            ->expectsOutputToContain('PETR4')
            ->assertSuccessful();

        $this->assertEquals(23.0, (float) $asset->fresh()->position->current_price);
    }

    public function test_asset_form_fills_name_class_and_sector_from_ticker(): void
    {
        Http::fake([
            'brapi.dev/api/quote/list*' => Http::response([
                'stocks' => [
                    [
                        'stock' => 'PETR4',
                        'name' => 'PETROLEO BRASILEIRO S.A. PETROBRAS',
                        'sector' => 'Energy Minerals',
                        'subsector' => 'Petróleo e Gás Integrado',
                        'type' => 'stock',
                        'subType' => 'stock',
                    ],
                    [
                        'stock' => 'PETR4F',
                        'name' => 'PETROLEO BRASILEIRO S.A. PETROBRAS',
                        'type' => 'stock',
                        'subType' => 'stock',
                    ],
                ],
            ]),
        ]);

        Volt::test('investments.assets.index')
            ->call('create')
            ->set('ticker', 'petr4')
            ->assertSet('ticker', 'PETR4')
            ->assertSet('name', 'PETROLEO BRASILEIRO S.A. PETROBRAS')
            ->assertSet('sector', 'Petróleo e Gás Integrado')
            ->assertSet('lookupStatus', 'Nome, classe e setor preenchidos a partir da B3.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assets', [
            'ticker' => 'PETR4',
            'name' => 'PETROLEO BRASILEIRO S.A. PETROBRAS',
            'sector' => 'Petróleo e Gás Integrado',
        ]);
        $this->assertDatabaseHas('asset_classes', ['slug' => 'acoes', 'name' => 'Ações']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/quote/list') && str_contains($request->url(), 'search=PETR4'));
    }

    public function test_asset_form_maps_fii_class_from_ticker(): void
    {
        Http::fake([
            'brapi.dev/api/quote/list*' => Http::response([
                'stocks' => [[
                    'stock' => 'HGLG11',
                    'name' => 'CSHG LOGÍSTICA',
                    'sector' => 'Finance',
                    'subsector' => 'Multicategoria',
                    'type' => 'fund',
                    'subType' => 'fii',
                ]],
            ]),
        ]);

        Volt::test('investments.assets.index')
            ->call('create')
            ->set('ticker', 'HGLG11')
            ->assertSet('name', 'CSHG LOGÍSTICA')
            ->assertSet('sector', 'Multicategoria');

        $this->assertDatabaseHas('asset_classes', ['slug' => 'fiis']);
    }

    public function test_unlisted_ticker_does_not_call_the_market_api(): void
    {
        Http::fake();

        Volt::test('investments.assets.index')
            ->call('create')
            ->set('ticker', 'CDB-2027')
            ->set('name', 'CDB Banco')
            ->set('newClass', 'Renda fixa')
            ->assertSet('lookupStatus', '');

        Http::assertNothingSent();
    }

    public function test_unknown_listed_ticker_asks_for_manual_data(): void
    {
        Http::fake([
            'brapi.dev/api/quote/list*' => Http::response(['stocks' => []]),
        ]);

        Volt::test('investments.assets.index')
            ->call('create')
            ->set('ticker', 'XXXX4')
            ->assertHasErrors('ticker')
            ->assertSet('name', '');
    }

    public function test_token_is_sent_when_configured(): void
    {
        config(['services.brapi.token' => 'test-token']);
        $asset = $this->listedAsset('MXRF11');
        $this->buy($asset, 100, 10);

        Http::fake([
            'brapi.dev/*' => Http::response([
                'results' => [[
                    'symbol' => 'MXRF11',
                    'data' => [
                        'regularMarketPrice' => 10.2,
                        'regularMarketTime' => '2026-09-09T20:45:30.000Z',
                    ],
                ]],
            ]),
        ]);

        app(RefreshAssetQuotesService::class)->refresh();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains($request->url(), 'symbols=MXRF11'));
        $this->assertEquals(10.2, (float) $asset->fresh()->position->current_price);
        $this->assertEquals('2026-09-09', $asset->fresh()->quotes()->first()?->date?->format('Y-m-d'));
    }

    private function fakeQuote(float $price, string $ticker = 'PETR4'): void
    {
        Http::fake([
            'brapi.dev/*' => Http::response([
                'results' => [[
                    'symbol' => $ticker,
                    'data' => [
                        'regularMarketPrice' => $price,
                        'regularMarketTime' => '2026-09-09T20:45:30.000Z',
                    ],
                ]],
            ]),
        ]);
    }

    private function listedAsset(string $ticker): Asset
    {
        $class = AssetClass::firstOrCreate(['slug' => 'acoes'], ['name' => 'Ações']);

        return Asset::create(['ticker' => $ticker, 'name' => $ticker, 'asset_class_id' => $class->id]);
    }

    private function unlistedAsset(string $ticker): Asset
    {
        $class = AssetClass::firstOrCreate(['slug' => 'outros'], ['name' => 'Outros']);

        return Asset::create(['ticker' => $ticker, 'name' => $ticker, 'asset_class_id' => $class->id]);
    }

    private function buy(Asset $asset, float $quantity, float $price): void
    {
        AssetOperation::create([
            'asset_id' => $asset->id,
            'date' => '2026-08-01',
            'type' => 'buy',
            'quantity' => $quantity,
            'unit_price' => $price,
            'fees' => 0,
            'total' => $quantity * $price,
        ]);
    }
}

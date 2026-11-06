<?php

namespace Tests\Feature\Investments;

use App\Domains\Investments\Jobs\RefreshAssetQuotesJob;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetQuote;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Domains\Investments\Services\RefreshAssetQuotesService;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa8QuotesB3Test extends TestCase
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
            B3PositionSnapshot::create(['import_batch_id' => $batch->id, 'asset_id' => $a->id, 'base_date' => '2026-10-30', 'ticker' => $t, 'origin' => 'custodia', 'quantity' => $q, 'quantity_custody' => $q, 'quantity_lent' => 0, 'average_price' => $pm, 'average_price_status' => $pm ? 'confirmado' : 'nao_informado', 'close_price' => $mv / $q, 'market_value' => $mv, 'source_key' => "b3pos:2026-10-30:$t"]);
        }
        Asset::create(['ticker' => 'OLDD3', 'name' => 'Só histórico', 'asset_class_id' => $class->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeBrapi(): void
    {
        Http::fake(function ($request) {
            $s = $request['symbols'];
            $price = ['AAAA3' => 13.0, 'BBBB3' => 5.0][$s] ?? 1.0;

            return Http::response(['results' => [['symbol' => $s, 'regularMarketPrice' => $price, 'regularMarketTime' => '2026-11-05T17:30:00.000Z']]]);
        });
    }

    public function test_refresh_quotes_only_b3_portfolio_sequentially_with_source_time(): void
    {
        $this->fakeBrapi();
        $r = app(RefreshAssetQuotesService::class)->refresh();
        $this->assertSame(['AAAA3', 'BBBB3'], $r['updated']);
        Http::assertSentCount(2); // OLDD3 (só histórico) não é consultado
        $q = AssetQuote::where('asset_id', Asset::where('ticker', 'AAAA3')->value('id'))->first();
        $this->assertSame('2026-11-05 17:30:00', $q->market_time->utc()->toDateTimeString());
        $this->assertSame('brapi', $q->source);
        $this->assertEquals(1200, B3PositionSnapshot::where('ticker', 'AAAA3')->value('market_value')); // foto B3 intacta
        $this->assertNull(B3PositionSnapshot::where('ticker', 'BBBB3')->value('average_price')); // PM intacto
    }

    public function test_summary_uses_latest_quote_and_shows_delay(): void
    {
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertEquals(1400, $s['market_value']);
        $this->assertSame(0, $s['quoted_count']);
        $this->fakeBrapi();
        app(RefreshAssetQuotesService::class)->refresh();
        $s = app(PortfolioProfitabilityService::class)->summary();
        $this->assertEquals(1300 + 250, $s['market_value']);
        $this->assertEquals(1400, $s['b3_market_value']);
        $this->assertEquals(300, $s['unrealized_pnl']); // só AAAA3 tem PM
        $this->assertSame(2, $s['quoted_count']);
        Volt::test('investments.dashboard')->assertSee('R$ 1.550,00')->assertSee('atraso ~30 min')->assertSee('05/11/2026 14:30')->assertSee('R$ 1.400,00');
    }

    public function test_quote_older_than_base_date_is_ignored(): void
    {
        AssetQuote::create(['asset_id' => Asset::where('ticker', 'AAAA3')->value('id'), 'date' => '2026-10-01', 'price' => 99]);
        $this->assertEquals(1400, app(PortfolioProfitabilityService::class)->summary()['market_value']);
    }

    public function test_schedule_every_30_minutes_on_trading_hours_plus_close(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->description, RefreshAssetQuotesJob::class) || $e->description === 'investment-quotes-intraday');
        $this->assertCount(2, $events);
        $intraday = $events->first(fn ($e) => $e->description === 'investment-quotes-intraday');
        $this->assertSame('*/30 * * * 1-5', $intraday->expression);
        $this->assertSame('America/Sao_Paulo', $intraday->timezone);
        $this->assertTrue($intraday->withoutOverlapping);
        // between() fixa o intervalo ao registrar o agendamento (cada schedule:run reinicia a app), então reinicia a app em cada horário.
        $at = function (string $when) {
            Carbon::setTestNow(Carbon::parse($when, 'America/Sao_Paulo'));
            $this->refreshApplication();
            $e = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'investment-quotes-intraday');

            return $e->isDue(app()) && $e->filtersPass(app());
        };
        $this->assertTrue($at('2026-11-05 10:00'));
        $this->assertTrue($at('2026-11-05 17:30'));
        $this->assertFalse($at('2026-11-05 18:00'));
        $this->assertFalse($at('2026-11-05 09:30'));
        $this->assertFalse($at('2026-11-07 11:00')); // sábado
        $close = $events->first(fn ($e) => $e !== $intraday);
        $this->assertSame('30 18 * * 1-5', $close->expression);
    }
}

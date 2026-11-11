<?php

namespace Tests\Feature;

use App\Domains\Partnership\Models\Partnership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Datas sugeridas/gravadas automaticamente usam o "hoje do escritório" (America/Fortaleza).
 * Simula 30/11/2026 às 22:00 (UTC-3) = 01/12/2026 01:00 UTC: tudo deve sugerir 30/11 (e o mês de novembro).
 */
class BusinessDateDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** Arquivos corrigidos: não podem voltar a usar o "hoje" em UTC. */
    private const FILES = [
        'app/Domains/Writs/Services/WritService.php',
        'app/Domains/Writs/Listeners/CreateIncomeOnFinalized.php',
        'app/Domains/Writs/Listeners/CreateExpenseOnPaid.php',
        'app/Domains/Banking/Services/ReceivableService.php',
        'app/Domains/Brokers/Services/BrokerCommissionService.php',
        'app/Domains/Brokers/Listeners/CreateExpenseOnCommissionPaid.php',
        'app/Http/Controllers/BrokerReportPdfController.php',
        'resources/views/livewire/divisions/show.blade.php',
        'resources/views/livewire/investments/positions.blade.php',
        'resources/views/livewire/investments/reports/profitability.blade.php',
        'resources/views/livewire/writs/form.blade.php',
        'resources/views/livewire/writs/show.blade.php',
        'resources/views/livewire/writs/kanban.blade.php',
        'resources/views/livewire/brokers/commissions/form.blade.php',
        'resources/views/livewire/brokers/index.blade.php',
        'resources/views/livewire/brokers/advances/form.blade.php',
        'resources/views/livewire/brokers/show.blade.php',
        'resources/views/livewire/banking/transactions/index.blade.php',
        'resources/views/livewire/banking/receivables/index.blade.php',
        'resources/views/livewire/banking/recurring/index.blade.php',
        'resources/views/livewire/partnership/reports.blade.php',
        'resources/views/livewire/partnership/distributions/index.blade.php',
        'resources/views/livewire/partnership/form.blade.php',
        'resources/views/livewire/partnership/expenses/index.blade.php',
        'resources/views/livewire/partnership/contributions/index.blade.php',
        'resources/views/livewire/bens/index.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($u);
        Carbon::setTestNow(Carbon::parse('2026-12-01 01:00:00', 'UTC')); // 30/11 22:00 em Fortaleza
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_business_date_is_fortaleza_at_22h(): void
    {
        $this->assertSame('2026-12-01', now()->toDateString()); // relógio do sistema continua em UTC
        $this->assertSame('2026-11-30', \App\Support\BusinessDate::today());
    }

    public function test_fixed_files_do_not_use_utc_today(): void
    {
        $pattern = '/(?<![\w:>$\\\\])(?:now|today)\(\)->(?:toDateString\(\)|format\([\'"]Y-m-d[\'"]\)|startOfMonth\(\)|endOfMonth\(\)|startOfYear\(\))/';
        foreach (self::FILES as $f) {
            $src = file_get_contents(base_path($f));
            $this->assertSame(0, preg_match_all($pattern, $src, $m), "$f ainda usa hoje em UTC: ".implode(', ', $m[0] ?? []));
        }
    }

    public function test_lancamentos_month_filter_and_recurring_start(): void
    {
        Volt::test('banking.transactions.index')->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-30');
        Volt::test('banking.recurring.index')->assertSet('start_date', '2026-11-30');
    }

    public function test_partnership_dates(): void
    {
        $p = Partnership::create(['name' => 'Escritório', 'cnpj' => '00.000.000/0001-00', 'participation_percentage' => 30, 'joined_at' => '2026-01-01', 'status' => true]);
        Volt::test('partnership.expenses.index', ['partnership' => $p])->assertSet('expenseDate', '2026-11-30')->assertSet('filterFrom', '2026-11-01')->assertSet('filterTo', '2026-11-30');
        Volt::test('partnership.contributions.index', ['partnership' => $p])->assertSet('contribDate', '2026-11-30')->assertSet('filterFrom', '2026-11-01');
        Volt::test('partnership.distributions.index', ['partnership' => $p])->assertSet('distDate', '2026-11-30')->assertSet('filterTo', '2026-11-30');
    }

    public function test_manual_quote_at_22h_suggests_today_and_saves(): void
    {
        $class = \App\Domains\Investments\Models\AssetClass::query()->create(['name' => 'Ações', 'code' => 'acoes', 'slug' => 'acoes']);
        $asset = \App\Domains\Investments\Models\Asset::query()->create(['ticker' => 'TEST4', 'name' => 'Teste', 'asset_class_id' => $class->id]);
        \App\Domains\Investments\Models\AssetPosition::query()->create(['asset_id' => $asset->id, 'quantity' => 10, 'average_price' => 10, 'total_invested' => 100]);
        Volt::test('investments.positions')->call('startQuote', $asset->id)->assertSet('quoteDate', '2026-11-30')
            ->set('quotePrice', '12,50')->call('saveQuote')->assertHasNoErrors();
        $this->assertSame('2026-11-30', \Illuminate\Support\Facades\DB::table('asset_quotes')->where('asset_id', $asset->id)->value('date') ? substr((string) \Illuminate\Support\Facades\DB::table('asset_quotes')->where('asset_id', $asset->id)->value('date'), 0, 10) : null);
    }

    public function test_user_typed_date_is_kept(): void
    {
        Volt::test('banking.recurring.index')->set('start_date', '2026-12-05')->assertSet('start_date', '2026-12-05');
    }
}

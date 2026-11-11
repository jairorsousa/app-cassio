<?php

namespace Tests\Feature\Ui;

use App\Domains\Divisions\Models\DivisionGroup;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Segunda rodada de setinhas: Requisitórios, Investimentos, Sociedade e relatórios de corretores. */
class TableSortSecondRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($this->admin);
        Livewire::withoutLazyLoading();
    }

    public function test_screens_load_default_sort_remember_choice_and_reject_invalid_column(): void
    {
        $screens = [
            'writs.reports' => ['writs_report_open', 'forecast', 'asc', 'beneficiary'],
            'writs.kanban' => ['writs_metrics_months', 'month', 'asc', 'received'],
            'investments.assets.index' => ['assets', 'ticker', 'asc', 'name'],
            'investments.operations.index' => ['operations', 'date', 'desc', 'total'],
            'investments.dividends.index' => ['dividends', 'date', 'desc', 'total'],
            'investments.positions' => ['positions', 'market', 'desc', 'ticker'],
            'investments.b3.index' => ['b3_incomes', 'date', 'desc', 'net'],
            'investments.reports.profitability' => ['profitability', 'asset', 'asc', 'result'],
            'investments.statements.index' => ['statement_events', 'date', 'desc', 'amount'],
            'brokers.reports' => ['broker_report_commissions', 'date', 'desc', 'amount'],
        ];

        foreach ($screens as $component => [$list, $defaultBy, $defaultDir, $other]) {
            Livewire::withoutLazyLoading();
            $c = Volt::test($component)->assertSet('sortBy', $defaultBy)->assertSet('sortDir', $defaultDir);
            $c->call('sortByColumn', $other)->assertSet('sortBy', $other)->assertSet('sortDir', 'asc');
            $this->assertSame([$other, 'asc'], UserPreferences::sort($this->admin->fresh(), $list), $component);
            $c->call('sortByColumn', 'coluna-invalida')->assertSet('sortBy', $defaultBy)->assertSet('sortDir', $defaultDir);
        }
    }

    public function test_secondary_tables_keep_their_own_order(): void
    {
        $c = Volt::test('writs.kanban');
        $c->call('sortByColumn', 'year', 'writs_metrics_years');
        $this->assertSame(['year', 'asc'], UserPreferences::sort($this->admin->fresh(), 'writs_metrics_years'));
        $this->assertSame(['month', 'asc'], UserPreferences::sort($this->admin->fresh(), 'writs_metrics_months'));
        $c->assertSeeHtml('data-sort-list="writs_metrics_months"');
    }
}

<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Dashboard\Support\DashboardPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardV2LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_widget_has_group_and_size(): void
    {
        foreach (array_keys(DashboardPreferences::WIDGETS) as $k) {
            $this->assertArrayHasKey($k, DashboardPreferences::LAYOUT);
            $this->assertArrayHasKey(DashboardPreferences::group($k), DashboardPreferences::GROUPS);
        }
    }

    public function test_free_move_skips_hidden_and_sizes_normalize(): void
    {
        $p = DashboardPreferences::defaults();
        $this->assertSame(['own_result', 'income_expense', 'cash_position'], array_slice(DashboardPreferences::moveTo($p, 'cash_position', 'income_expense')['order'], 0, 3));
        $p['hidden'] = ['moving_averages'];
        $o = DashboardPreferences::move($p, 'income_expense', 1)['order'];
        $this->assertSame(['invoices', 'moving_averages', 'income_expense'], array_slice($o, 2, 3));
        $n = DashboardPreferences::normalize(['sizes' => ['accounts' => 'm', 'loans' => 'xx', 'nope' => 'l']]);
        $this->assertSame(['accounts' => 'm'], $n['sizes']);
        $this->assertSame('m', DashboardPreferences::size('accounts', $n));
        $this->assertSame('l', DashboardPreferences::size('patrimony', $n));
    }

    public function test_legacy_prefs_keep_hidden_and_get_writs_months(): void
    {
        $n = DashboardPreferences::normalize(['order' => ['accounts', 'cash_position'], 'hidden' => ['distribution', 'loans'], 'writs_months' => 7]);
        $this->assertSame(['distribution', 'loans'], $n['hidden']);
        $this->assertSame(6, $n['writs_months']);
        $this->assertSame(18, DashboardPreferences::normalize(['writs_months' => 18])['writs_months']);
    }

    public function test_dashboard_renders_groups_new_menu_and_saves_months(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        Livewire::withoutLazyLoading();
        Volt::test('dashboard.index')
            ->assertSee('+ Novo')->assertSee('Editar painel')
            ->assertSee('Requisitórios a receber (6 meses)')
            ->call('setWritsMonths', 12)
            ->assertSee('Requisitórios a receber (12 meses)')
            ->call('toggleCustomize')->assertSee('+ Adicionar card')->assertSee('Restaurar padrão')
            ->call('setWidgetSize', 'accounts', 's')
            ->call('toggleWidget', 'loans')
            ->assertSee('Empréstimos em aberto');
        $saved = DashboardPreferences::for($user->fresh());
        $this->assertSame(12, $saved['writs_months']);
        $this->assertSame('s', $saved['sizes']['accounts']);
        $this->assertContains('loans', $saved['hidden']);
    }
}

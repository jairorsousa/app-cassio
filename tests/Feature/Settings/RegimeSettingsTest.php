<?php

namespace Tests\Feature\Settings;

use App\Domains\Banking\Services\OwnFinancialService;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Regime do resultado saiu do topo do dashboard e foi para Configurações (por usuário, padrão Competência). */
class RegimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(Access::MODULES)])->save();

        return $u;
    }

    public function test_new_user_defaults_to_accrual(): void
    {
        $u = $this->admin();
        $this->assertSame('accrual', $u->fresh()->financial_regime);
        $this->actingAs($u);
        $this->assertSame(OwnFinancialService::ACCRUAL, OwnFinancialService::currentRegime());

        $u->forceFill(['financial_regime' => 'qualquer'])->save();
        $this->assertSame(OwnFinancialService::ACCRUAL, OwnFinancialService::currentRegime());
    }

    public function test_settings_saves_regime_per_user_and_dashboard_reads_it(): void
    {
        $me = $this->admin();
        $other = $this->admin();
        $this->actingAs($me);

        Volt::test('settings.index')
            ->assertSet('regime', 'accrual')
            ->assertSee('Regime do resultado')
            ->set('regime', 'cash')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('cash', $me->fresh()->financial_regime);
        $this->assertSame('accrual', $other->fresh()->financial_regime, 'Só a conta de quem salvou muda.');

        Livewire::withoutLazyLoading();
        Volt::test('dashboard.index')->assertSee('Regime: Caixa');

        Volt::test('settings.index')->assertSet('regime', 'cash')->set('regime', 'xyz')->call('save')->assertHasErrors('regime');
        Volt::test('settings.index')->call('restoreDefaults')->assertSet('regime', 'accrual');
    }

    public function test_dashboard_top_has_no_regime_selector_but_keeps_period_text_and_refresh(): void
    {
        $this->actingAs($this->admin());
        Livewire::withoutLazyLoading();

        Volt::test('dashboard.index')
            ->assertDontSeeHtml('financial-regime')
            ->assertDontSee('Regime do resultado')
            ->assertSee('Período')
            ->assertSee('Regime: Competência')
            ->assertSee('Atualizar agora');
    }
}

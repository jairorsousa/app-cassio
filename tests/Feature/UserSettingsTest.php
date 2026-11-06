<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_defaults_and_menu_link(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/configuracoes')->assertOk()->assertSee('Cor de destaque')->assertSee('Área inicial');
        $this->actingAs($u)->get('/profile')->assertSee(route('settings'), false);
        $this->get('/configuracoes')->assertOk();
    }

    public function test_guest_redirected(): void
    {
        $this->get('/configuracoes')->assertRedirect('/login');
    }

    public function test_invalid_values_rejected(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        Volt::test('settings.index')->set('theme', 'neon')->set('accent', '#000')->set('text_size', 'x')->set('density', 'z')->set('home', 'admin')
            ->call('save')->assertHasErrors(['theme', 'accent', 'text_size', 'density', 'home']);
        $this->assertNull($u->fresh()->preferences);
    }

    public function test_save_is_per_user_and_applied_server_side(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a);
        Volt::test('settings.index')->set('theme', 'dark')->set('accent', 'azul')->set('text_size', 'grande')->set('density', 'compacta')->set('home', 'investimentos')->call('save')->assertHasNoErrors();
        $this->assertSame('dark', $a->fresh()->preferences['theme']);
        $this->assertNull($b->fresh()->preferences);
        $this->actingAs($a->fresh())->get('/configuracoes')->assertSee('data-theme="dark"', false)->assertSee('data-text-size="grande"', false)->assertSee('data-density="compacta"', false)->assertSee('--colors-primary-g500:#5b9cf5', false);
        $this->actingAs($b)->get('/configuracoes')->assertSee('data-theme="light"', false)->assertDontSee('--colors-primary-g500:#5b9cf5', false)->assertSee('--colors-primary-g500:#ff6f00', false);
    }

    public function test_home_redirect_uses_preference(): void
    {
        $u = User::factory()->create(['preferences' => ['home' => 'investimentos']]);
        $this->actingAs($u)->get('/')->assertRedirect(route('investments.dashboard', absolute: false));
        $u->forceFill(['preferences' => ['home' => 'financeiro']])->save();
        $this->actingAs($u->fresh())->get('/')->assertRedirect(route('banking.transactions.index', absolute: false));
        $this->assertSame('/dashboard', UserPreferences::homeUrl(User::factory()->create()));
    }

    public function test_restore_defaults_and_cancel(): void
    {
        $u = User::factory()->create(['preferences' => ['theme' => 'dark', 'accent' => 'roxo']]);
        $this->actingAs($u);
        Volt::test('settings.index')->assertSet('accent', 'roxo')->call('restoreDefaults')->assertSet('accent', 'laranja')->assertSet('theme', 'light')
            ->call('cancel')->assertSet('accent', 'roxo');
        $this->assertSame('roxo', $u->fresh()->preferences['accent']);
    }
}

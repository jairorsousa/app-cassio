<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_expose_a_mobile_menu_with_all_modules(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['dashboard', 'investments.dashboard', 'banking.dashboard', 'contacts.index'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('Abrir menu', false)
                ->assertSee('Fechar menu', false)
                ->assertSee('id="app-mobile-nav"', false)
                ->assertSee('Financeiro', false)
                ->assertSee('Requisitórios', false)
                ->assertSee('Corretores', false)
                ->assertSee('Transmissão', false)
                ->assertSee('Investimento', false)
                ->assertSee('Sociedade', false)
                ->assertSee('Contatos', false);
        }
    }
}

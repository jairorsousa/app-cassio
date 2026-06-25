<?php

namespace Tests\Feature\Brokers;

use App\Domains\Brokers\Models\CaseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CaseTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipos_caso_page_is_accessible(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('brokers.tipos-caso.index'))
            ->assertOk()
            ->assertSee('Novo Tipo de Caso')
            ->assertSee('Nenhum tipo de caso cadastrado');
    }

    public function test_legacy_case_types_url_redirects_to_tipos_caso(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/brokers/case-types')
            ->assertRedirect('/brokers/tipos-caso');
    }

    public function test_legacy_case_types_url_does_not_resolve_as_broker_show(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/brokers/case-types')
            ->assertRedirect('/brokers/tipos-caso');
    }

    public function test_user_can_create_and_edit_case_type(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('brokers.tipos-caso.index')
            ->set('name', 'Previdenciário')
            ->set('status', true)
            ->call('save')
            ->assertHasNoErrors();

        $caseType = CaseType::firstOrFail();

        $this->assertDatabaseHas('case_types', [
            'id' => $caseType->id,
            'name' => 'Previdenciário',
            'status' => true,
        ]);

        Volt::test('brokers.tipos-caso.index')
            ->call('edit', $caseType->id)
            ->set('name', 'Trabalhista')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('case_types', [
            'id' => $caseType->id,
            'name' => 'Trabalhista',
        ]);
    }

    public function test_active_case_types_appear_in_broker_commission_form(): void
    {
        $this->actingAs(User::factory()->create());

        CaseType::create(['name' => 'Ativo', 'status' => true]);
        CaseType::create(['name' => 'Inativo', 'status' => false]);

        Volt::test('brokers.tipos-caso.index')
            ->assertSee('Ativo')
            ->assertSee('Inativo');
    }
}
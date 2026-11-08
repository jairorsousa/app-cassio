<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ViewerColorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_green_red_from_logged_user_perspective_on_index_and_group(): void
    {
        $admin = User::factory()->create(['name' => 'Cassio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $ana->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g = $svc->createGroup($admin, ['name' => 'Casa', 'type' => 'familia']);
        $am = $svc->addMember($admin, $g, 'Ana', 'ana@example.com', null);
        $me = $g->fresh()->ownerMember();
        $svc->saveExpense($admin, $g->fresh(), ['description' => 'Mercado', 'category' => 'outros', 'date' => '2026-10-10', 'amount' => '100.00',
            'paid_by_member_id' => $me->id, 'split_mode' => 'equal', 'inputs' => [$me->id => 1, $am->id => 1], 'installments' => 1, 'transaction_id' => null, 'notes' => '']);

        Volt::test('divisions.index')->assertSeeHtml('data-sign="pos">No total, <span class="text-green-600" data-amount>te devem R$ 50,00</span>')
            ->assertSeeHtml('data-person-row data-sign="pos"')->assertSeeHtml('text-green-600" data-group-balance');
        Volt::test('divisions.show', ['group' => $g->fresh()])->assertSeeHtml('data-sign="pos"><span class="text-green-600" data-amount>Devem a você R$ 50,00</span> no total')->assertSeeHtml('data-lent="5000"');

        $this->actingAs($ana);
        Volt::test('divisions.index')->assertSeeHtml('data-sign="neg">No total, <span class="text-red-600" data-amount>você deve R$ 50,00</span>')
            ->assertSeeHtml('data-person-row data-sign="neg"')->assertSeeHtml('text-red-600" data-group-balance')
            ->assertSee('Cassio')->assertSeeHtml('data-settle-link')->assertDontSeeHtml('data-charge');
        Volt::test('divisions.show', ['group' => $g->fresh()])->assertSeeHtml('data-sign="neg"><span class="text-red-600" data-amount>Você deve R$ 50,00</span> no total')->assertSeeHtml('<span class="text-red-600" data-amount>Você deve R$ 50,00</span> a <strong>Cassio</strong>')
            ->assertSee('Cassio pagou R$ 100,00')->assertSeeHtml('data-lent="-5000"')->assertSee('você pegou emprestado');
    }
}

<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GroupSummaryViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_and_my_part_from_viewer_perspective(): void
    {
        $admin = User::factory()->create(['name' => 'Cassio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g = $svc->createGroup($admin, ['name' => 'Casa', 'type' => 'familia']);
        $ana = $svc->addMember($admin, $g, 'Ana', 'ana@example.com', null);
        $bia = $svc->addMember($admin, $g, 'Bia', 'bia@example.com', null);
        $me = $g->fresh()->ownerMember();
        $mk = fn ($d, $amt, $payer, $ids) => $svc->saveExpense($admin, $g->fresh(), ['description' => $d, 'category' => 'outros', 'date' => '2026-10-10', 'amount' => $amt,
            'paid_by_member_id' => $payer, 'split_mode' => 'equal', 'inputs' => array_fill_keys($ids, 1), 'installments' => 1, 'transaction_id' => null, 'notes' => '']);
        $mk('Mercado', '90.00', $me->id, [$me->id, $ana->id, $bia->id]);   // eu emprestei 60
        $mk('Pizza', '40.00', $ana->id, [$me->id, $ana->id]);              // peguei 20
        $mk('Uber', '10.00', $ana->id, [$ana->id, $bia->id]);              // não envolvido
        Volt::test('divisions.show', ['group' => $g->fresh()])
            ->assertSeeHtml('data-sign="pos">Devem a você R$ 40,00 no total')
            ->assertSee('Você pagou R$ 90,00')->assertSee('Ana pagou R$ 40,00')
            ->assertSeeHtml('data-lent="6000"')->assertSeeHtml('data-lent="-2000"')->assertSeeHtml('data-lent="none"')
            ->assertSee('não envolvido')->assertSeeHtml('data-fab-add')->assertSee('Bia</strong> deve R$ 35,00 a você', false);
    }
}

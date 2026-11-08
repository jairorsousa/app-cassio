<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProportionalBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_proportional_split_exact_cents(): void
    {
        $t = app(DivisionService::class)->simplify([1 => 100001, 2 => 50000, 3 => -75001, 4 => -75000]);
        $sum = fn ($k, $v) => collect($t)->where($k, $v)->sum('cents');
        $this->assertSame(75001, $sum('from', 3));
        $this->assertSame(75000, $sum('from', 4));
        $this->assertSame(100001, $sum('to', 1));
        $this->assertSame(50000, $sum('to', 2));
        $this->assertCount(4, $t, 'cada devedor deve a cada credor');
    }

    public function test_farm_scenario_debtor_owes_half_to_each_creditor_and_rebalances_after_settlement(): void
    {
        $admin = User::factory()->create(['name' => 'Cássio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g = $svc->createGroup($admin, ['name' => 'Compra fazenda', 'type' => 'familia']);
        $h = $svc->addMember($admin, $g, 'Heleno', 'h@example.com', null);
        $ma = $svc->addMember($admin, $g, 'Marina', 'ma@example.com', null);
        $mc = $svc->addMember($admin, $g, 'Márcio', 'mc@example.com', null);
        $me = $g->fresh()->ownerMember();
        $all = [$me->id => 1, $h->id => 1, $ma->id => 1, $mc->id => 1];
        $mk = fn ($amt, $payer) => $svc->saveExpense($admin, $g->fresh(), ['description' => 'Parcela', 'category' => 'outros', 'date' => '2026-10-10', 'amount' => $amt,
            'paid_by_member_id' => $payer, 'split_mode' => 'equal', 'inputs' => $all, 'installments' => 1, 'transaction_id' => null, 'notes' => '']);
        $mk('100000.00', $me->id);
        $mk('100000.00', $h->id);
        $mk('100000.00', $ma->id);   // cada um deve 75.000; Márcio deve 75.000, Cássio e Heleno +25.000, Marina +25.000
        $t = collect($svc->simplify($svc->netBalances($g->fresh())));
        $this->assertSame(2500000, $t->where('from', $mc->id)->where('to', $me->id)->sum('cents'));
        $this->assertSame(2500000, $t->where('from', $mc->id)->where('to', $h->id)->sum('cents'));
        $this->assertSame(2500000, $t->where('from', $mc->id)->where('to', $ma->id)->sum('cents'));

        // Márcio paga 30.000 só ao Heleno: Heleno passa a dever 5.000 e tudo se reequilibra entre Cássio e Marina.
        $svc->settle($admin, $g->fresh(), ['from_member_id' => $mc->id, 'to_member_id' => $h->id, 'amount' => '30000.00', 'date' => '2026-10-11', 'bank_account_id' => null, 'notes' => '']);
        $t = collect($svc->simplify($svc->netBalances($g->fresh())));
        $this->assertSame(2250000, $t->where('from', $mc->id)->where('to', $me->id)->sum('cents'));
        $this->assertSame(2250000, $t->where('from', $mc->id)->where('to', $ma->id)->sum('cents'));
        $this->assertSame(250000, $t->where('from', $h->id)->where('to', $me->id)->sum('cents'));
        $this->assertSame(250000, $t->where('from', $h->id)->where('to', $ma->id)->sum('cents'));
        $this->assertSame(0, $t->where('to', $h->id)->sum('cents'));
    }
}

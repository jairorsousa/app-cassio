<?php

namespace Tests\Feature\Divisions;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DivisionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function participant(string $name = 'Matheus', string $email = 'matheus@example.com'): User
    {
        $u = User::factory()->create(['name' => $name, 'email' => $email]);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();

        return $u;
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    /** @return array{0: DivisionGroup, 1: \App\Domains\Divisions\Models\DivisionMember, 2: \App\Domains\Divisions\Models\DivisionMember, 3: \App\Domains\Divisions\Models\DivisionMember} */
    private function group(User $admin, bool $requiresApproval = true): array
    {
        $g = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos', 'requires_approval' => $requiresApproval]);
        $b = $this->svc()->addMember($admin, $g, 'Matheus', 'matheus@example.com');
        $c = $this->svc()->addMember($admin, $g, 'Ana', 'ana@example.com');
        $g->load('members');

        return [$g, $g->ownerMember(), $b, $c];
    }

    public function test_splits_equal_percent_amount_and_shares(): void
    {
        $s = $this->svc();
        $this->assertSame([1 => 3334, 2 => 3333, 3 => 3333], array_map(fn ($r) => $r['cents'], $s->computeShares(10000, 'equal', [1 => 1, 2 => 1, 3 => 1])));
        $this->assertSame([1 => 5000, 2 => 3000, 3 => 2000], array_map(fn ($r) => $r['cents'], $s->computeShares(10000, 'percent', [1 => '50', 2 => '30', 3 => '20'])));
        $this->assertSame([1 => 2550, 2 => 7450], array_map(fn ($r) => $r['cents'], $s->computeShares(10000, 'amount', [1 => '25,50', 2 => '74.50'])));
        $this->assertSame([1 => 6667, 2 => 3333], array_map(fn ($r) => $r['cents'], $s->computeShares(10000, 'shares', [1 => '2', 2 => '1'])));
    }

    public function test_split_sum_validation(): void
    {
        foreach ([['percent', [1 => '50', 2 => '40']], ['amount', [1 => '10,00', 2 => '20,00']], ['equal', []]] as [$mode, $inputs]) {
            try {
                $this->svc()->computeShares(10000, $mode, $inputs);
                $this->fail("{$mode} deveria falhar");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('splitRows', $e->errors());
            }
        }
    }

    public function test_installments_and_balances_with_simplified_debts(): void
    {
        $admin = $this->admin();
        [$g, $a, $b, $c] = $this->group($admin);
        $this->actingAs($admin);
        $created = $this->svc()->saveExpense($admin, $g, ['description' => 'Hotel', 'category' => 'viagem', 'date' => '2026-11-01', 'amount' => '1.000,00',
            'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1, $c->id => 1], 'installments' => 5]);
        $this->assertCount(5, $created);
        $this->assertSame('Hotel (2/5)', $created[1]->description);
        $this->assertSame('2026-12-01', $created[1]->date->toDateString());
        $this->assertEqualsWithDelta(1000.0, $created->sum(fn ($e) => (float) $e->amount), 0.001);

        // B pagou 300 para C e A meio a meio: B deveria receber 150 de C... simplificação.
        $this->svc()->saveExpense($admin, $g, ['description' => 'Jantar', 'date' => '2026-11-02', 'amount' => '300', 'paid_by_member_id' => $b->id,
            'split_mode' => 'amount', 'inputs' => [$a->id => '150', $c->id => '150']]);
        $net = $this->svc()->netBalances($g);
        $this->assertSame(0, array_sum($net));
        $this->assertSame(100000 - 33334 - 15000, $net[$a->id]);
        $transfers = $this->svc()->simplify($net);
        $this->assertLessThanOrEqual(2, count($transfers));
        $after = $net;
        foreach ($transfers as $t) {
            $after[$t['from']] += $t['cents'];
            $after[$t['to']] -= $t['cents'];
        }
        $this->assertSame([0, 0, 0], array_values($after));
    }

    public function test_simplify_reduces_chain_to_single_payment(): void
    {
        // A deve 10 a B, B deve 10 a C → A paga C direto.
        $this->assertSame([['from' => 1, 'to' => 3, 'cents' => 1000]], $this->svc()->simplify([1 => -1000, 2 => 0, 3 => 1000]));
    }

    public function test_participant_sees_only_own_groups_and_no_financeiro(): void
    {
        $admin = $this->admin();
        [$g] = $this->group($admin);
        $this->svc()->createGroup($admin, ['name' => 'Família secreta', 'type' => 'familia']);
        $p = $this->participant();
        $this->actingAs($p);
        $this->get(route('divisions.index'))->assertOk()->assertSee('Viagem')->assertDontSee('Família secreta');
        $this->get(route('divisions.show', $g))->assertOk();
        $other = DivisionGroup::where('name', 'Família secreta')->first();
        $this->get(route('divisions.show', $other))->assertForbidden();
        $this->get(route('banking.dashboard'))->assertForbidden();
        $this->get(route('banking.transactions.index'))->assertForbidden();
        $this->get(route('divisions.index'))->assertDontSee('Financeiro</a>', false);
    }

    public function test_user_without_module_cannot_open_divisions(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['financeiro']])->save();
        $this->actingAs($u)->get(route('divisions.index'))->assertForbidden();
    }

    public function test_participant_group_request_and_admin_approval(): void
    {
        $admin = $this->admin();
        $p = $this->participant();
        $g = $this->svc()->createGroup($p, ['name' => 'Futebol', 'type' => 'amigos']);
        $this->assertSame('pending', $g->status);
        $this->assertCount(2, $g->members);
        $this->actingAs($p)->get(route('divisions.index'))->assertSee('Aguardando aprovação');
        $this->actingAs($p)->get(route('divisions.show', $g))->assertNotFound();
        try {
            $this->svc()->decideGroup($p, $g, true);
            $this->fail('participante não aprova');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->actingAs($admin);
        Volt::test('divisions.index')->assertSee('Aguardando sua aprovação')->call('decideGroup', $g->id, true);
        $this->assertSame('approved', $g->fresh()->status);
    }

    public function test_participant_expense_needs_approval_when_group_requires(): void
    {
        $admin = $this->admin();
        [$g, $a, $b] = $this->group($admin, true);
        $p = $this->participant();
        $this->svc()->claimMemberships($p);
        $e = $this->svc()->saveExpense($p, $g->fresh(), ['description' => 'Gasolina', 'date' => '2026-11-03', 'amount' => '200,00', 'paid_by_member_id' => $b->id,
            'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1]])->first();
        $this->assertSame('pending', $e->status);
        $this->assertSame(0, $this->svc()->netBalances($g)[$b->id]);
        $this->svc()->decideExpense($admin, $e, true);
        $this->assertSame(10000, $this->svc()->netBalances($g)[$b->id]);
        $this->assertSame(['expense_created', 'expense_approved'], $e->audits()->reorder('id')->pluck('action')->all());

        $this->svc()->updateGroup($admin, $g, ['requires_approval' => false]);
        $e2 = $this->svc()->saveExpense($p, $g->fresh(), ['description' => 'Pedágio', 'date' => '2026-11-03', 'amount' => '20', 'paid_by_member_id' => $b->id,
            'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1]])->first();
        $this->assertSame('approved', $e2->status);
    }

    public function test_settlement_creates_linked_financeiro_entry(): void
    {
        $admin = $this->admin();
        [$g, $a, $b] = $this->group($admin);
        $account = BankAccount::create(['name' => 'Conta Teste', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $this->actingAs($admin);
        $this->svc()->saveExpense($admin, $g, ['description' => 'Aluguel', 'date' => '2026-11-01', 'amount' => '500', 'paid_by_member_id' => $a->id,
            'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1]]);
        $s = $this->svc()->settle($admin, $g, ['from_member_id' => $b->id, 'to_member_id' => $a->id, 'amount' => '250,00', 'date' => '2026-11-05', 'bank_account_id' => $account->id]);
        $tx = Transaction::find($s->transaction_id);
        $this->assertSame('income', $tx->type);
        $this->assertSame('division_settlement', $tx->source_type);
        $this->assertSame($s->id, (int) $tx->source_id);
        $this->assertEqualsWithDelta(250.0, (float) $tx->amount, 0.001);
        $this->assertSame(0, $this->svc()->netBalances($g)[$b->id]);

        try {
            $this->svc()->settle($admin, $g, ['from_member_id' => $a->id, 'to_member_id' => $b->id, 'amount' => '10', 'date' => '2026-11-05']);
            $this->fail('conta obrigatória');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settleAccount', $e->errors());
        }
        $this->svc()->deleteSettlement($admin, $s);
        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
    }

    public function test_expense_links_existing_financeiro_entry_only_once(): void
    {
        $admin = $this->admin();
        [$g, $a, $b] = $this->group($admin);
        $account = BankAccount::create(['name' => 'Conta Teste', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $tx = Transaction::create(['type' => 'expense', 'date' => '2026-11-01', 'amount' => 90, 'description' => 'Mercado', 'status' => 'settled', 'bank_account_id' => $account->id]);
        $this->actingAs($admin);
        $data = ['description' => 'Mercado', 'date' => '2026-11-01', 'amount' => '90', 'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1], 'transaction_id' => $tx->id];
        $e = $this->svc()->saveExpense($admin, $g, $data)->first();
        $this->assertSame($tx->id, $e->transaction_id);
        $this->assertSame(1, Transaction::count());
        try {
            $this->svc()->saveExpense($admin, $g, $data);
            $this->fail('vínculo duplicado');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('transactionId', $ex->errors());
        }
        try {
            $this->svc()->saveExpense($admin, $g, array_replace($data, ['paid_by_member_id' => $b->id]));
            $this->fail('só quando o Cassio pagou');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('transactionId', $ex->errors());
        }
    }

    public function test_invite_link_grants_only_divisions(): void
    {
        $admin = $this->admin();
        [$g, , $b] = $this->group($admin);
        $this->actingAs($admin);
        $link = $this->svc()->inviteMember($admin, $b);
        $this->assertStringContainsString('/convite/', $link);
        $inv = UserInvitation::where('email', 'matheus@example.com')->first();
        $this->assertSame(['divisoes'], $inv->modules);
        $token = basename(parse_url($link, PHP_URL_PATH));
        $user = UserAccessService::accept($token, 'SenhaForte123!');
        $this->assertSame(['divisoes'], $user->modules);
        $this->svc()->claimMemberships($user);
        $this->assertSame($user->id, $b->fresh()->user_id);
        $this->assertSame('divisions.index', \App\Support\Access::homeRoute($user));
    }

    public function test_pages_render_with_forms_and_buttons(): void
    {
        $admin = $this->admin();
        [$g] = $this->group($admin);
        $this->actingAs($admin);
        $this->get(route('divisions.index'))->assertOk()->assertSee('Divisões');
        Volt::test('divisions.show', ['group' => $g])->call('newExpense')
            ->assertSeeHtml('x-money')->assertSee('Salvar e novo')->assertSee('Cancelar')->assertSeeHtml('data-receipt')
            ->set('expenseDescription', 'Pizza')->set('expenseAmount', '90.00')->call('saveExpense', true)
            ->assertHasNoErrors()->assertSet('showExpenseModal', true)->assertSet('expenseDescription', '');
        $this->assertSame(1, DivisionExpense::count());
        $html = $this->get(route('dashboard'))->getContent();
        $this->assertMatchesRegularExpression('/Sociedade.*Divisões/s', $html);
    }
}

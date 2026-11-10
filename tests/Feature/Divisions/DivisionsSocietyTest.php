<?php

namespace Tests\Feature\Divisions;

use App\Domains\Assets\Models\Property;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Regra A (despesas divididas em todos os grupos) e regra B (bem comprado em sociedade). */
class DivisionsSocietyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DivisionGroup $g;

    private array $m;

    private BankAccount $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Cassio']);
        $this->admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($this->admin);
        $svc = $this->svc();
        $this->g = $svc->createGroup($this->admin, ['name' => 'Fazenda', 'type' => 'familia', 'requires_approval' => false]);
        $h = $svc->addMember($this->admin, $this->g, 'Heleno', 'heleno@example.com');
        $mc = $svc->addMember($this->admin, $this->g, 'Márcio', 'marcio@example.com');
        $ma = $svc->addMember($this->admin, $this->g, 'Marina', 'marina@example.com');
        $this->g->load('members');
        $this->m = ['c' => $this->g->ownerMember(), 'h' => $h, 'mc' => $mc, 'ma' => $ma];
        $this->acc = BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 10000, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        Category::create(['name' => 'Propriedade rural / Terra', 'type' => 'expense', 'status' => true]);
        \App\Domains\Divisions\Models\DivisionCategory::create(['key' => 'propriedade_rural_terra', 'label' => 'Propriedade rural / Terra', 'created_by' => $this->admin->id]);
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function all4(): array
    {
        return [$this->m['c']->id => 1, $this->m['h']->id => 1, $this->m['mc']->id => 1, $this->m['ma']->id => 1];
    }

    private function expense(string $payer, string $amount, string $date, array $extra = []): DivisionExpense
    {
        $data = ['description' => $extra['description'] ?? 'Trator', 'category' => 'propriedade_rural_terra', 'date' => $date, 'amount' => $amount,
            'paid_by_member_id' => $this->m[$payer]->id, 'split_mode' => 'equal', 'inputs' => $this->all4()] + $extra;
        if ($payer === 'c' && ! array_key_exists('payment_method', $data)) {
            $data += ['payment_method' => 'account', 'bank_account_id' => $this->acc->id];
        }

        return $this->svc()->saveExpense($this->admin, $this->g, $data)->first();
    }

    private function nov(string $regime = 'accrual'): array
    {
        return app(OwnFinancialService::class)->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59:59'), $regime);
    }

    public function test_owner_paid_expense_counts_only_his_share_but_account_has_full_outflow(): void
    {
        $e = $this->expense('c', '1.312,50', '2026-11-09');
        $tx = Transaction::find($e->transaction_id);
        $this->assertEquals(1312.50, (float) $tx->amount);
        $this->assertEqualsWithDelta(10000 - 1312.50, $this->acc->fresh()->balance(), 0.001, 'conta: saída do valor inteiro');
        $this->assertEquals(328.13, $this->nov()['expense']);
        $this->assertEquals(328.13, $this->nov('cash')['expense']);
        $report = app(CashflowReportService::class)->realized(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), [], 'accrual');
        $this->assertEquals(328.13, $report['by_category']['Propriedade rural / Terra']['own_expense'], 'gráfico de despesas usa só a parte dele');
        $this->assertEquals(984.37, $report['by_category']['Propriedade rural / Terra']['third_party_out'], 'o resto é adiantamento aos sócios');
    }

    public function test_other_paid_expense_creates_accountless_share_and_no_balance_change(): void
    {
        $balances = BankAccount::all()->mapWithKeys(fn ($a) => [$a->id => $a->balance()]);
        $e = $this->expense('h', '1.312,50', '2026-11-08', ['description' => 'Aceiro']);
        $share = Transaction::where('source_type', 'division_share')->where('source_id', $e->id)->first();
        $this->assertNotNull($share);
        $this->assertNull($share->bank_account_id);
        $this->assertNull($share->credit_card_id);
        $this->assertEquals(328.13, (float) $share->amount);
        $this->assertSame('2026-11-08', $share->date->toDateString());
        $this->assertSame('Propriedade rural / Terra', $share->category->name);
        $this->assertTrue($share->isReadOnly());
        $this->assertEquals(328.13, $this->nov()['expense']);
        $this->assertEquals($balances->all(), BankAccount::all()->mapWithKeys(fn ($a) => [$a->id => $a->balance()])->all());

        // editar / recusar / excluir mantém o registro em dia
        $this->svc()->saveExpense($this->admin, $this->g, ['description' => 'Aceiro', 'category' => 'propriedade_rural_terra', 'date' => '2026-11-08', 'amount' => '400,00',
            'paid_by_member_id' => $this->m['h']->id, 'split_mode' => 'equal', 'inputs' => $this->all4()], null, $e);
        $this->assertEquals(100.0, (float) $share->fresh()->amount);
        $this->svc()->deleteExpense($this->admin, $e->fresh());
        $this->assertSame(0, Transaction::where('source_type', 'division_share')->count());
    }

    public function test_settlements_are_out_of_result_and_zeroed_group_equals_own_share(): void
    {
        $this->expense('c', '1.312,50', '2026-11-09');
        $this->expense('h', '1.312,50', '2026-11-08', ['description' => 'Aceiro']);
        // Márcio e Marina devem 656,24 cada; acertam com Cassio e Heleno
        $net = $this->svc()->netBalances($this->g);
        $this->assertSame(65624, $net[$this->m['c']->id]);
        $s1 = $this->svc()->settle($this->admin, $this->g, ['from_member_id' => $this->m['mc']->id, 'to_member_id' => $this->m['c']->id, 'amount' => '656,24', 'date' => '2026-11-10', 'bank_account_id' => $this->acc->id]);
        $this->svc()->settle($this->admin, $this->g, ['from_member_id' => $this->m['ma']->id, 'to_member_id' => $this->m['h']->id, 'amount' => '656,24', 'date' => '2026-11-10']);
        $this->assertSame([0, 0, 0, 0], array_values($this->svc()->netBalances($this->g)));
        $this->assertEqualsWithDelta(10000 - 1312.50 + 656.24, $this->acc->fresh()->balance(), 0.001, 'acerto entra na conta');
        $t = $this->nov();
        $this->assertEquals(0.0, $t['income'], 'acerto não é receita');
        $this->assertEquals(656.26, $t['expense'], 'grupo zerado: despesas = exatamente a parte dele (328,13 + 328,13)');
        $report = app(CashflowReportService::class)->realized(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), [], 'accrual');
        $this->assertArrayNotHasKey('— sem categoria —', $report['by_category']->all());

        // Cassio pagando acerto também fica fora
        $this->svc()->deleteSettlement($this->admin, $s1);
        $this->svc()->settle($this->admin, $this->g, ['from_member_id' => $this->m['c']->id, 'to_member_id' => $this->m['h']->id, 'amount' => '10,00', 'date' => '2026-11-10', 'bank_account_id' => $this->acc->id]);
        $this->assertEquals(656.26, $this->nov()['expense']);
    }

    public function test_card_division_purchase_in_cash_regime_counts_only_share(): void
    {
        $card = CreditCard::create(['name' => 'BTG 0052', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        $e = $this->expense('c', '400,00', '2026-10-10', ['payment_method' => 'card', 'credit_card_id' => $card->id]);
        $tx = Transaction::find($e->transaction_id);
        $this->assertEquals(100.0, app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), 'accrual')['expense']);
        app(InvoicePaymentService::class)->pay($tx->invoice, $this->acc, 400, '2026-11-05');
        $this->assertEquals(100.0, $this->nov('cash')['expense']);
    }

    private function shared(): Property
    {
        return Property::create(['kind' => 'rural', 'description' => 'Fazenda João Barbudo', 'owner' => 'cassio', 'status' => 'active', 'division_group_id' => $this->g->id]);
    }

    public function test_purchase_only_in_linked_group_and_uses_asset_category(): void
    {
        try {
            $this->expense('c', '1000', '2026-11-01', ['kind' => 'purchase']);
            $this->fail('sem bem ligado não pode');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expenseKind', $e->errors());
        }
        $this->shared();
        $e = $this->expense('c', '157.500,00', '2026-11-01', ['kind' => 'purchase', 'purchase_mode' => 'advance', 'description' => '1ª parcela']);
        $this->assertSame('purchase', $e->kind);
        $this->assertSame('advance', $e->purchase_mode);
        $tx = Transaction::find($e->transaction_id);
        $this->assertSame(Category::assetPurchaseCategoryId(), $tx->category_id);
        $this->assertEquals(0.0, $this->nov()['expense'], 'compra do bem fora do resultado');
        $this->assertSame(-3937500, $this->svc()->netBalances($this->g)[$this->m['h']->id], 'adiantamento: Heleno deve a parte dele');
        $h = $this->expense('h', '157.500,00', '2026-11-01', ['kind' => 'expense', 'description' => 'Parcela Heleno']);
        $this->assertSame('expense', $h->kind, 'padrão é Despesa');
        $this->assertSame(1, Transaction::where('source_type', 'division_share')->count());
        $this->svc()->setKind($this->admin, $h, 'purchase', 'advance');
        $this->assertSame(0, Transaction::where('source_type', 'division_share')->count(), 'compra paga por sócio não vira despesa do Cassio');
        $this->assertEquals(0.0, $this->nov()['expense']);
    }

    public function test_society_shares_follow_purchases_and_increase_mode(): void
    {
        $p = $this->shared();
        $this->expense('c', '157.500,00', '2026-07-09', ['kind' => 'purchase', 'description' => '1ª parcela Cassio']);
        $this->expense('h', '157.500,00', '2026-07-09', ['kind' => 'purchase', 'description' => '1ª parcela Heleno']);
        $this->expense('h', '1.312,50', '2026-11-08', ['description' => 'Aceiro']);
        $s = $p->fresh()->societySummary();
        $this->assertEquals(315000.0, $s['paid']);
        $this->assertNull($s['total']);
        $this->assertEquals(78750.0, $s['owner_part']);
        $this->assertEquals(0.25, $s['owner_percent']);
        $this->assertEquals([25.0, 25.0, 25.0, 25.0], array_column($s['members'], 'percent'));
        $this->assertEquals(78750.0, $p->fresh()->patrimonyValue(), 'sem valor: conta o que ele assumiu');

        // Cassio paga mais 85.000 aumentando a parte dele: sem dívida, percentual sobe
        $before = $this->svc()->netBalances($this->g);
        $inc = $this->expense('c', '85.000,00', '2026-11-10', ['kind' => 'purchase', 'purchase_mode' => 'increase', 'description' => 'Extra Cassio']);
        $this->assertSame([$this->m['c']->id => '85000.00'], $inc->shares()->pluck('amount', 'member_id')->all());
        $this->assertEquals($before, $this->svc()->netBalances($this->g), 'aumentar a parte não gera dívida');
        $s = $p->fresh()->societySummary();
        $this->assertEquals(163750.0, $s['owner_part']);
        $this->assertEqualsWithDelta(163750 / 400000, $s['owner_percent'], 0.0000001);
        $p->update(['acquisition_value' => 1000000, 'current_value' => 1200000]);
        $p = $p->fresh();
        $this->assertEquals(round(1200000 * 163750 / 400000, 2), $p->patrimonyValue());
        $this->assertEquals(600000.0, $p->societySummary()['remaining']);
        $this->assertEquals(0.0, $this->nov()['expense'] - 328.13, 'só o aceiro conta (parte dele)');
    }

    public function test_screens_bens_society_and_division_form(): void
    {
        $p = $this->shared();
        $this->expense('c', '157.500,00', '2026-07-09', ['kind' => 'purchase', 'description' => '1ª parcela Cassio']);
        Volt::test('bens.index')->call('openPayments', $p->id)
            ->assertSee('Compra em sociedade')->assertSee('Informe o valor total')->assertSee('Parte de cada sócio')->assertSee('Heleno')->assertSee('25,00%')
            ->call('newPayment')->assertRedirect(route('divisions.show', ['group' => $this->g->id, 'nova' => 'compra']));

        $this->get(route('divisions.show', ['group' => $this->g->id, 'nova' => 'compra']))->assertOk()->assertSee('Compra do bem')->assertSee('Adiantamento aos sócios')->assertSee('Aumentar minha parte');
        Volt::test('divisions.show', ['group' => $this->g])->call('newExpense')->assertSet('expenseKind', 'expense')
            ->set('expenseKind', 'purchase')->set('purchaseMode', 'increase')->assertSeeHtml('data-split-increase')
            ->set('expenseDescription', '2ª parcela')->set('expenseAmount', '10.000,00')->set('expenseDate', '2026-11-10')->set('paymentMethod', 'account')->set('payAccount', (string) $this->acc->id)
            ->call('saveExpense')->assertHasNoErrors();
        $e = DivisionExpense::where('description', '2ª parcela')->first();
        $this->assertSame(['purchase', 'increase'], [$e->kind, $e->purchase_mode]);
        Volt::test('bens.index')->call('openPayments', $p->id)->assertSee('2ª parcela')->assertSee('Aumentar minha parte');
    }

    public function test_rebuild_is_idempotent_and_unlinked_group_hides_kind(): void
    {
        $e = $this->expense('h', '120,00', '2026-11-07');
        Transaction::where('source_type', 'division_share')->forceDelete();
        $this->assertSame(['created' => 1, 'updated' => 0, 'removed' => 0], $this->svc()->rebuildOwnerShares());
        $this->assertSame(['created' => 0, 'updated' => 0, 'removed' => 0], $this->svc()->rebuildOwnerShares());
        $this->assertEquals(30.0, (float) Transaction::where('source_type', 'division_share')->value('amount'));
        Volt::test('divisions.show', ['group' => $this->g])->call('newExpense')->assertDontSeeHtml('data-expense-kind');
    }
}

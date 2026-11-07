<?php

namespace Tests\Feature\Divisions;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DivisionsPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function setup2(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Terra', 'type' => 'amigos', 'requires_approval' => false]);
        $b = $this->svc()->addMember($admin, $g, 'João', 'joao@example.com');
        $g->load('members');

        return [$admin, $g, $g->ownerMember(), $b];
    }

    private function data($a, $b, array $extra = []): array
    {
        return $extra + ['description' => 'Parcela da terra', 'category' => 'outros', 'date' => '2026-07-09', 'amount' => '1.000,00',
            'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1]];
    }

    public function test_new_category_is_created_and_usable(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $key = $this->svc()->createCategory($admin, 'Propriedade rural / Terra');
        $this->assertSame('Propriedade rural / Terra', DivisionExpense::categories()[$key]);
        $this->assertSame($key, $this->svc()->createCategory($admin, 'propriedade rural / terra'));
        $this->assertTrue(Category::where('name', 'Propriedade rural / Terra')->where('type', 'expense')->exists());
        $e = $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['category' => $key]))->first();
        $this->assertSame($key, $e->category);
        $this->assertSame('Propriedade rural / Terra', $e->categoryLabel());
    }

    public function test_paid_by_account_creates_synced_financeiro_expense(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $acc = BankAccount::create(['name' => 'Itaú', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $acc2 = BankAccount::create(['name' => 'BB', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $key = $this->svc()->createCategory($admin, 'Propriedade rural / Terra');
        $e = $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['category' => $key, 'payment_method' => 'account', 'bank_account_id' => $acc->id]))->first();
        $tx = Transaction::findOrFail($e->fresh()->transaction_id);
        $this->assertSame('expense', $tx->type);
        $this->assertSame('Divisões: Parcela da terra', $tx->description);
        $this->assertSame($acc->id, (int) $tx->bank_account_id);
        $this->assertEqualsWithDelta(1000.0, (float) $tx->amount, 0.001);
        $this->assertSame('2026-07-09', $tx->date->toDateString());
        $this->assertSame(Category::where('name', 'Propriedade rural / Terra')->value('id'), $tx->category_id);
        $this->assertSame('account', $e->fresh()->payment_method);

        $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['amount' => '1.200,00', 'description' => 'Parcela 1', 'payment_method' => 'account', 'bank_account_id' => $acc2->id]), null, $e->fresh());
        $this->assertSame(1, Transaction::count());
        $tx->refresh();
        $this->assertEqualsWithDelta(1200.0, (float) $tx->amount, 0.001);
        $this->assertSame('Divisões: Parcela 1', $tx->description);
        $this->assertSame($acc2->id, (int) $tx->bank_account_id);

        $this->svc()->deleteExpense($admin, $e->fresh());
        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
    }

    public function test_paid_by_card_goes_to_right_invoice_and_switching_removes_it(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $card = CreditCard::create(['name' => 'Visa', 'brand' => 'visa', 'limit' => 50000, 'closing_day' => 5, 'due_day' => 15, 'status' => true]);
        $acc = BankAccount::create(['name' => 'Itaú', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $e = $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['payment_method' => 'card', 'credit_card_id' => $card->id]))->first();
        $tx = Transaction::findOrFail($e->fresh()->transaction_id);
        $this->assertSame($card->id, (int) $tx->credit_card_id);
        $this->assertNull($tx->bank_account_id);
        $this->assertSame('2026-08', $tx->invoice->reference_month); // 09/07 depois do fechamento (dia 5) cai na fatura seguinte
        $this->assertEqualsWithDelta(1000.0, (float) $tx->invoice->fresh()->total, 0.001);

        $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['payment_method' => 'account', 'bank_account_id' => $acc->id]), null, $e->fresh());
        $tx->refresh();
        $this->assertNull($tx->credit_card_id);
        $this->assertSame($acc->id, (int) $tx->bank_account_id);
        $this->assertEqualsWithDelta(0.0, (float) \App\Domains\Banking\Models\CreditCardInvoice::first()->total, 0.001);

        $this->svc()->saveExpense($admin, $g, $this->data($a, $b), null, $e->fresh());
        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
        $this->assertNull($e->fresh()->transaction_id);
    }

    public function test_only_when_cassio_paid(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $acc = BankAccount::create(['name' => 'Itaú', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $this->expectException(ValidationException::class);
        $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['paid_by_member_id' => $b->id, 'payment_method' => 'account', 'bank_account_id' => $acc->id]));
    }

    public function test_modal_shows_payment_options_and_new_category(): void
    {
        [$admin, $g] = $this->setup2();
        Volt::test('divisions.show', ['group' => $g])->call('newExpense')
            ->assertSee('Como foi pago')->assertSee('+ Nova categoria')->assertSee('Vincular a lançamento existente')->assertDontSee('Já está no Financeiro')
            ->set('showNewCategory', true)->set('newCategoryLabel', 'Propriedade rural / Terra')->call('createCategory')
            ->assertSet('expenseCategory', 'propriedade_rural_terra')->assertSee('Propriedade rural / Terra');
    }

    public function test_participant_never_sees_cassio_financeiro(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $acc = BankAccount::create(['name' => 'Itaú Secreto', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $e = $this->svc()->saveExpense($admin, $g, $this->data($a, $b, ['payment_method' => 'account', 'bank_account_id' => $acc->id]))->first();
        $txId = $e->fresh()->transaction_id;
        Volt::test('divisions.show', ['group' => $g])->assertSee('Financeiro #'.$txId);

        $joao = User::factory()->create(['name' => 'João', 'email' => 'joao@example.com']);
        $joao->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $this->actingAs($joao);
        $c = Volt::test('divisions.show', ['group' => $g])
            ->assertSee('Parcela da terra')->assertDontSee('Financeiro #')->assertDontSee('Vinculada ao Financeiro')
            ->call('newExpense')->assertDontSee('Como foi pago')->assertDontSee('Itaú Secreto')->assertDontSee('Vincular a lançamento existente');
        $this->assertSame([], $c->viewData('accounts')->all());
        $this->assertSame([], $c->viewData('cards')->all());

        $this->expectException(ValidationException::class);
        $this->svc()->saveExpense($joao, $g, $this->data($a, $b, ['description' => 'X', 'paid_by_member_id' => $b->id, 'payment_method' => 'account', 'bank_account_id' => $acc->id]));
    }
}

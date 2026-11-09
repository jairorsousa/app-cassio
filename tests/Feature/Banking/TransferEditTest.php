<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransferService;
use App\Models\User;
use App\Support\AmountTone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Edição de transferência: os dois lados e os saldos de todas as contas (antigas e novas) acompanham. */
class TransferEditTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $a;

    private BankAccount $b;

    private BankAccount $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->a = BankAccount::create(['name' => 'Banco A', 'initial_balance' => 1000]);
        $this->b = BankAccount::create(['name' => 'Banco B', 'initial_balance' => 1000]);
        $this->c = BankAccount::create(['name' => 'Banco C', 'initial_balance' => 1000]);
    }

    private function transfer(float $amount = 100): Transaction
    {
        return app(TransferService::class)->execute($this->a, $this->b, $amount, now()->toDateString());
    }

    private function balances(): array
    {
        return array_map(fn ($acc) => round($acc->fresh()->balance(), 2), [$this->a, $this->b, $this->c]);
    }

    private function editAndSave(int $id, array $set)
    {
        $c = Volt::test('banking.transactions.index')->call('edit', $id);
        foreach ($set as $k => $v) {
            $c->set($k, $v);
        }

        return $c->call('saveTransaction')->assertHasNoErrors();
    }

    private function assertPair(Transaction $out, int $fromId, int $toId, float $amount): void
    {
        $out = $out->fresh();
        $in = Transaction::findOrFail($out->related_transaction_id);
        $this->assertSame($out->id, $in->related_transaction_id);
        $this->assertSame([$fromId, $toId], [$out->bank_account_id, $in->bank_account_id]);
        $this->assertEquals([-$amount, $amount], [(float) $out->amount, (float) $in->amount]);
        $this->assertSame(2, Transaction::where('type', 'transfer')->count(), 'sem lado órfão ou duplicado');
    }

    public function test_edit_loads_both_accounts_from_either_side(): void
    {
        $out = $this->transfer();
        Volt::test('banking.transactions.index')->call('edit', $out->id)
            ->assertSet('formBankAccountId', $this->a->id)->assertSet('formTransferToId', $this->b->id);
        Volt::test('banking.transactions.index')->call('edit', $out->related_transaction_id)
            ->assertSet('formBankAccountId', $this->a->id)->assertSet('formTransferToId', $this->b->id);
    }

    public function test_editing_origin_moves_the_outflow_and_fixes_all_balances(): void
    {
        $out = $this->transfer();
        $this->assertSame([900.0, 1100.0, 1000.0], $this->balances());
        $this->editAndSave($out->id, ['formBankAccountId' => $this->c->id]);
        $this->assertPair($out, $this->c->id, $this->b->id, 100);
        $this->assertSame([1000.0, 1100.0, 900.0], $this->balances());
        $this->assertSame('Transferência Saída Banco C → Banco B', $out->fresh()->description);
    }

    public function test_editing_destination_from_the_incoming_side(): void
    {
        $out = $this->transfer();
        $this->editAndSave($out->related_transaction_id, ['formTransferToId' => $this->c->id]);
        $this->assertPair($out, $this->a->id, $this->c->id, 100);
        $this->assertSame([900.0, 1000.0, 1100.0], $this->balances());
    }

    public function test_editing_amount_updates_both_sides_and_keeps_signs(): void
    {
        $out = $this->transfer();
        $this->editAndSave($out->id, ['formAmount' => '250.50', 'formDate' => '2026-11-01']);
        $this->assertPair($out, $this->a->id, $this->b->id, 250.50);
        $this->assertSame('2026-11-01', Transaction::find($out->related_transaction_id)->date->toDateString());
        $this->assertSame([749.5, 1250.5, 1000.0], $this->balances());
    }

    public function test_deleting_either_side_removes_both(): void
    {
        $out = $this->transfer();
        Volt::test('banking.transactions.index')->call('delete', $out->related_transaction_id)->call('confirmDeletion');
        $this->assertSame(0, Transaction::where('type', 'transfer')->count());
        $this->assertSame([1000.0, 1000.0, 1000.0], $this->balances());
    }

    public function test_expense_converted_into_transfer_and_back(): void
    {
        $cat = Category::create(['name' => 'Mercado', 'type' => 'expense', 'status' => true]);
        $expense = Transaction::create(['type' => 'expense', 'date' => now()->toDateString(), 'amount' => 80, 'description' => 'PIX para B', 'status' => 'settled', 'bank_account_id' => $this->a->id, 'category_id' => $cat->id]);
        $this->editAndSave($expense->id, ['formType' => 'transfer', 'formTransferToId' => $this->b->id]);
        $this->assertPair($expense, $this->a->id, $this->b->id, 80);
        $this->assertSame('PIX para B', $expense->fresh()->description); // descrição do usuário é mantida
        $this->assertSame([920.0, 1080.0, 1000.0], $this->balances());

        $this->editAndSave($expense->id, ['formType' => 'expense', 'formCategoryId' => $cat->id, 'formDescription' => 'PIX para B']);
        $this->assertSame(0, Transaction::where('type', 'transfer')->count());
        $e = $expense->fresh();
        $this->assertSame(['expense', 80.0, null], [$e->type, (float) $e->amount, $e->related_transaction_id]);
        $this->assertSame([920.0, 1000.0, 1000.0], $this->balances());
    }

    public function test_single_sided_transfer_keeps_its_sign_when_edited(): void
    {
        $single = Transaction::create(['type' => 'transfer', 'date' => now()->toDateString(), 'amount' => -50, 'description' => 'Vínculo importado', 'status' => 'settled', 'bank_account_id' => $this->a->id]);
        $this->editAndSave($single->id, ['formAmount' => '60']);
        $this->assertSame(-60.0, (float) $single->fresh()->amount);
        $this->assertSame(1, Transaction::where('type', 'transfer')->count());
    }

    public function test_amount_tone_helper_and_component(): void
    {
        $this->assertSame('out', AmountTone::for('expense', 10));
        $this->assertSame('in', AmountTone::for('expense', -10)); // estorno
        $this->assertSame('in', AmountTone::for('income', 10));
        $this->assertSame('neutral', AmountTone::for('transfer', -10));
        $this->assertSame('out', AmountTone::for('invoice_payment', 10));
        $html = (string) $this->blade('<x-fx.amount type="expense" :amount="-12.5" />');
        $this->assertStringContainsString('fx-amount-in', $html);
        $this->assertStringContainsString('R$ 12,50', $html);

        $this->transfer();
        $cat = Category::create(['name' => 'Salário', 'type' => 'income', 'status' => true]);
        Transaction::create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => 5, 'description' => 'Receita X', 'status' => 'settled', 'bank_account_id' => $this->a->id, 'category_id' => $cat->id]);
        $page = Volt::test('banking.transactions.index');
        $page->assertSeeHtml('data-amount-tone="neutral"')->assertSeeHtml('data-amount-tone="in"');
    }
}

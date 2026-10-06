<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionAllocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransactionAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_allocation_for_income_and_expense_recalculates_when_total_changes(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['income', 'expense'] as $type) {
            $first = Category::create(['name' => 'A '.$type, 'type' => $type]);
            $second = Category::create(['name' => 'B '.$type, 'type' => $type]);
            $component = Volt::test('banking.transactions.index')->call('create', $type)
                ->set('formAmount', '1250.00')->set('formDescription', 'Rateio percentual')
                ->set('formAllocationEnabled', true)->set('formAllocationMode', 'percentage')
                ->set('formAllocationRows', [
                    ['key' => 'a', 'category_id' => $first->id, 'percentage' => '20.00'],
                    ['key' => 'b', 'category_id' => $second->id, 'percentage' => '80.00'],
                ])->assertSee('R$ 250,00')->assertSee('R$ 1.000,00')
                ->set('formAmount', '2000.00')->assertSee('R$ 400,00')->assertSee('R$ 1.600,00')
                ->call('saveTransaction')->assertHasNoErrors();
            $transaction = Transaction::where('type', $type)->sole();
            $this->assertEquals(['400.00', '1600.00'], $transaction->allocations->pluck('amount')->all());
        }
    }

    public function test_percentage_allocation_rejects_incomplete_excessive_or_invalid_percentages(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'income']);
        $second = Category::create(['name' => 'B', 'type' => 'income']);
        foreach ([['20', '79.99'], ['20', '81'], ['0', '100'], ['-20', '120'], ['20.001', '79.999']] as [$a, $b]) {
            Volt::test('banking.transactions.index')->call('create', 'income')
                ->set('formAmount', '1250.00')->set('formDescription', 'Inválido')
                ->set('formAllocationEnabled', true)->set('formAllocationMode', 'percentage')
                ->set('formAllocationRows', [
                    ['key' => 'a', 'category_id' => $first->id, 'percentage' => $a],
                    ['key' => 'b', 'category_id' => $second->id, 'percentage' => $b],
                ])->call('saveTransaction')->assertHasErrors(['formAllocationRows']);
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_percentage_conversion_preserves_total_cents_and_switching_modes(): void
    {
        $service = app(TransactionAllocationService::class);
        $rows = $service->fromPercentages('10.01', [
            ['percentage' => '33.33'], ['percentage' => '33.33'], ['percentage' => '33.34'],
        ]);
        $this->assertEquals(['3.34', '3.33', '3.34'], array_column($rows, 'amount'));
        $percentages = $service->toPercentages('0.03', [['amount' => '0.01'], ['amount' => '0.01'], ['amount' => '0.01']]);
        $this->assertEquals(['33.34', '33.33', '33.33'], array_column($percentages, 'percentage'));

        $this->actingAs(User::factory()->create());
        Volt::test('banking.transactions.index')->call('create', 'income')->set('formAmount', '1250.00')
            ->set('formAllocationEnabled', true)->set('formAllocationRows', [
                ['key' => 'a', 'category_id' => null, 'amount' => '250.00'],
                ['key' => 'b', 'category_id' => null, 'amount' => '1000.00'],
            ])->set('formAllocationMode', 'percentage')
            ->assertSet('formAllocationRows.0.percentage', '20.00')
            ->assertSet('formAllocationRows.1.percentage', '80.00')
            ->set('formAllocationMode', 'amount')
            ->assertSet('formAllocationRows.0.amount', '250.00')
            ->assertSet('formAllocationRows.1.amount', '1000.00');
    }

    public function test_saved_transaction_can_be_allocated_by_percentage(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'income']);
        $second = Category::create(['name' => 'B', 'type' => 'income']);
        $transaction = Transaction::create(['type' => 'income', 'date' => now(), 'amount' => '1250.00', 'description' => 'Receita']);
        Volt::test('banking.transactions.index')->call('openAllocation', $transaction->id)
            ->set('allocationMode', 'percentage')->set('allocationRows', [
                ['key' => 'a', 'category_id' => $first->id, 'percentage' => '20'],
                ['key' => 'b', 'category_id' => $second->id, 'percentage' => '80'],
            ])->call('saveAllocation')->assertHasNoErrors()->assertSet('showAllocationModal', false);
        $this->assertEquals(['250.00', '1000.00'], $transaction->fresh()->allocations->pluck('amount')->all());
    }

    public function test_creating_income_and_expense_with_allocation_keeps_one_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 2000]);
        foreach (['expense', 'income'] as $type) {
            $first = Category::create(['name' => 'Principal '.$type, 'type' => $type]);
            $second = Category::create(['name' => 'Outra '.$type, 'type' => $type]);
            Volt::test('banking.transactions.index')
                ->call('create', $type)
                ->set('formAmount', '1.000,00')
                ->assertSet('formAmount', '1000.00')
                ->set('formBankAccountId', $account->id)
                ->set('formDescription', 'Rateio '.$type)
                ->set('formAllocationEnabled', true)
                ->set('formAllocationRows', [
                    ['key' => 'a', 'category_id' => $first->id, 'amount' => '900.00'],
                    ['key' => 'b', 'category_id' => $second->id, 'amount' => '100.00'],
                ])
                ->call('saveTransaction')
                ->assertHasNoErrors()
                ->assertSet('showFormModal', false);
            $transaction = Transaction::where('type', $type)->sole();
            $this->assertNull($transaction->category_id);
            $this->assertEquals('1000.00', $transaction->amount);
            $this->assertEquals(['900.00', '100.00'], $transaction->allocations->pluck('amount')->all());
            $report = Volt::test('banking.reports.cashflow')->viewData('byCategory');
            $this->assertEquals(900, $report[$first->name][$type]);
            $this->assertEquals(100, $report[$second->name][$type]);
        }
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(2000.0, $account->fresh()->balance());
    }

    public function test_invalid_inline_allocation_does_not_create_a_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'expense']);
        $second = Category::create(['name' => 'B', 'type' => 'expense']);
        $income = Category::create(['name' => 'Receita', 'type' => 'income']);
        foreach ([
            [$second->id, '99.99'], [$first->id, '100.00'], [$income->id, '100.00'], [$second->id, '0.00'],
        ] as [$id, $amount]) {
            Volt::test('banking.transactions.index')->call('create', 'expense')
                ->set('formAmount', '1000.00')->set('formDescription', 'Inválido')
                ->set('formAllocationEnabled', true)
                ->set('formAllocationRows', [
                    ['key' => 'a', 'category_id' => $first->id, 'amount' => '900.00'],
                    ['key' => 'b', 'category_id' => $id, 'amount' => $amount],
                ])->call('saveTransaction')->assertHasErrors(['formAllocationRows'])
                ->assertSet('showFormModal', true);
            $this->assertDatabaseCount('transactions', 0);
            $this->assertDatabaseCount('transaction_allocations', 0);
        }
    }

    public function test_inline_allocation_can_be_adjusted_with_the_total_and_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'income']);
        $second = Category::create(['name' => 'B', 'type' => 'income']);
        $transaction = Transaction::create(['type' => 'income', 'date' => now(), 'amount' => 1000, 'description' => 'Receita']);
        app(TransactionAllocationService::class)->replace($transaction, [
            ['category_id' => $first->id, 'amount' => '900.00'], ['category_id' => $second->id, 'amount' => '100.00'],
        ]);
        Volt::test('banking.transactions.index')->call('edit', $transaction->id)
            ->assertSet('formAllocationEnabled', true)->set('formAmount', '1100.00')
            ->set('formAllocationRows.0.amount', '1000.00')->call('saveTransaction')->assertHasNoErrors();
        $this->assertSame('1100.00', $transaction->fresh()->amount);
        $this->assertEquals(1100, $transaction->fresh()->allocations->sum('amount'));

        Volt::test('banking.transactions.index')->call('edit', $transaction->id)
            ->set('formAllocationEnabled', false)->set('formCategoryId', $first->id)
            ->call('saveTransaction')->assertHasNoErrors();
        $this->assertCount(0, $transaction->fresh()->allocations);
        $this->assertSame($first->id, $transaction->fresh()->category_id);
    }

    public function test_installment_allocation_preserves_each_invoice_and_category_total(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'expense']);
        $second = Category::create(['name' => 'B', 'type' => 'expense']);
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        Volt::test('banking.transactions.index')->call('create', 'card_expense')
            ->set('formCreditCardId', $card->id)->set('formAmount', '100.00')
            ->set('formInstallments', 3)->set('formDescription', 'Compra rateada')
            ->set('formAllocationEnabled', true)->set('formAllocationRows', [
                ['key' => 'a', 'category_id' => $first->id, 'amount' => '60.00'],
                ['key' => 'b', 'category_id' => $second->id, 'amount' => '40.00'],
            ])->call('saveTransaction')->assertHasNoErrors();
        $transactions = Transaction::with('allocations')->orderBy('installment_number')->get();
        $this->assertCount(3, $transactions);
        foreach ($transactions as $transaction) {
            $this->assertEquals((float) $transaction->amount, $transaction->allocations->sum('amount'));
            $this->assertEquals((float) $transaction->amount, (float) $transaction->invoice->total);
        }
        $allocations = $transactions->flatMap->allocations;
        $this->assertEquals(60, $allocations->where('category_id', $first->id)->sum('amount'));
        $this->assertEquals(40, $allocations->where('category_id', $second->id)->sum('amount'));
    }

    public function test_allocation_preserves_bank_balance_and_updates_category_report(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);
        $food = Category::create(['name' => 'Alimentação', 'type' => 'expense']);
        $home = Category::create(['name' => 'Moradia', 'type' => 'expense']);
        $transaction = Transaction::create([
            'type' => 'expense', 'date' => now()->toDateString(), 'amount' => '100.00',
            'description' => 'Compra', 'status' => 'settled', 'bank_account_id' => $account->id,
            'category_id' => $food->id, 'ofx_fitid' => 'ofx-001',
        ]);

        $this->get(route('banking.reports.cashflow'))->assertOk()->assertSee('R$ 100,00');

        Volt::test('banking.transactions.index')
            ->call('openAllocation', $transaction->id)
            ->assertSet('showAllocationModal', true)
            ->set('allocationRows', [
                ['key' => 'a', 'category_id' => $food->id, 'amount' => '60.25'],
                ['key' => 'b', 'category_id' => $home->id, 'amount' => '39.75'],
            ])
            ->call('saveAllocation')
            ->assertHasNoErrors()
            ->assertSet('showAllocationModal', false);

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'amount' => '100.00', 'category_id' => null]);
        $this->assertDatabaseHas('transaction_allocations', ['transaction_id' => $transaction->id, 'category_id' => $food->id, 'amount' => '60.25']);
        $this->assertDatabaseHas('transaction_allocations', ['transaction_id' => $transaction->id, 'category_id' => $home->id, 'amount' => '39.75']);
        $this->assertSame(900.0, $account->fresh()->balance());

        $report = Volt::test('banking.reports.cashflow');
        $this->assertSame(100.0, (float) $report->viewData('totalExpense'));
        $this->assertSame(60.25, (float) $report->viewData('byCategory')['Alimentação']['expense']);
        $this->assertSame(39.75, (float) $report->viewData('byCategory')['Moradia']['expense']);

        Volt::test('banking.transactions.index')
            ->set('category', (string) $home->id)
            ->assertSee('Compra')
            ->assertSee('39,75');
    }

    public function test_invalid_allocation_is_rejected_without_changing_existing_rows(): void
    {
        $expense = Category::create(['name' => 'Despesa', 'type' => 'expense']);
        $otherExpense = Category::create(['name' => 'Outra despesa', 'type' => 'expense']);
        $income = Category::create(['name' => 'Receita', 'type' => 'income']);
        $transaction = Transaction::create([
            'type' => 'expense', 'date' => now()->toDateString(), 'amount' => '100.00',
            'description' => 'Compra', 'status' => 'settled',
        ]);
        $service = app(TransactionAllocationService::class);
        $service->replace($transaction, [
            ['category_id' => $expense->id, 'amount' => '60.00'],
            ['category_id' => $otherExpense->id, 'amount' => '40.00'],
        ]);

        foreach ([
            [['category_id' => $expense->id, 'amount' => '60.00'], ['category_id' => $otherExpense->id, 'amount' => '39.99']],
            [['category_id' => $expense->id, 'amount' => '60.00'], ['category_id' => $income->id, 'amount' => '40.00']],
            [['category_id' => $expense->id, 'amount' => '60.00'], ['category_id' => $expense->id, 'amount' => '40.00']],
            [['category_id' => $expense->id, 'amount' => '100.00'], ['category_id' => $otherExpense->id, 'amount' => '0.00']],
        ] as $invalidRows) {
            try {
                $service->replace($transaction, $invalidRows);
                $this->fail('Rateio inválido foi aceito.');
            } catch (\InvalidArgumentException $e) {
                $this->assertCount(2, $transaction->fresh()->allocations);
                $this->assertSame('60.00', $transaction->fresh()->allocations->first()->amount);
            }
        }
    }

    public function test_editing_allocated_total_requires_adjusting_or_removing_allocation(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Category::create(['name' => 'A', 'type' => 'expense']);
        $second = Category::create(['name' => 'B', 'type' => 'expense']);
        $transaction = Transaction::create([
            'type' => 'expense', 'date' => now()->toDateString(), 'amount' => '100.00',
            'description' => 'Compra', 'status' => 'settled',
        ]);
        app(TransactionAllocationService::class)->replace($transaction, [
            ['category_id' => $first->id, 'amount' => '50.00'],
            ['category_id' => $second->id, 'amount' => '50.00'],
        ]);

        Volt::test('banking.transactions.index')
            ->call('edit', $transaction->id)
            ->set('formAmount', '110.00')
            ->call('saveTransaction')
            ->assertHasErrors(['formAllocationRows']);

        $this->assertSame('100.00', $transaction->fresh()->amount);

        Volt::test('banking.transactions.index')
            ->call('openAllocation', $transaction->id)
            ->call('clearAllocation')
            ->assertHasNoErrors();

        $this->assertCount(0, $transaction->fresh()->allocations);
        $this->assertNull($transaction->fresh()->category_id);
    }
}

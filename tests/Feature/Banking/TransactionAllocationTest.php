<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionAllocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransactionAllocationTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertHasErrors(['formAmount']);

        $this->assertSame('100.00', $transaction->fresh()->amount);

        Volt::test('banking.transactions.index')
            ->call('openAllocation', $transaction->id)
            ->call('clearAllocation')
            ->assertHasNoErrors();

        $this->assertCount(0, $transaction->fresh()->allocations);
        $this->assertNull($transaction->fresh()->category_id);
    }
}

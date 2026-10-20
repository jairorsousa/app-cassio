<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\TransactionAllocationService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerLedgerDeletionService;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use App\Domains\Brokers\Services\BrokerStatementService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProcessReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $category = Category::create(['name' => 'Honorários próprios', 'type' => 'income', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'amount' => '30000.00', 'date' => now()->toDateString(), 'description' => 'Recebimento', 'status' => 'settled', 'bank_account_id' => $account->id]);
        $data = ['broker_id' => $broker->id, 'client_name' => 'Cliente', 'office_name' => 'Escritório', 'fees' => '20', 'fees_mode' => 'percent', 'broker_amount' => '25', 'office_amount' => '25', 'commission_mode' => 'percent', 'category_id' => $category->id, 'expenses' => [], 'advances' => []];

        return [$receipt, $broker, $account, $category, $data];
    }

    private function expense(Broker $broker, BankAccount $account, string $amount = '500.00'): BrokerProcessExpense
    {
        return app(BrokerProcessExpenseService::class)->save($broker, ['date' => now()->toDateString(), 'amount' => $amount, 'description' => 'Passagem', 'bank_account_id' => $account->id]);
    }

    public function test_two_level_division_preserves_cash_and_classifies_only_own_fees(): void
    {
        [$receipt, $broker, $account, $category, $data] = $this->example();
        $expense = $this->expense($broker, $account);
        $advance = app(BrokerAdvanceService::class)->register(['broker_id' => $broker->id, 'date' => now()->toDateString(), 'amount' => 700, 'bank_account_id' => $account->id])['advance'];
        $data['expenses'] = [$expense->id => '200.00'];
        $data['advances'] = [$advance->id => '300.00'];
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)->call('save')->assertHasNoErrors()->assertDispatched('process-receipt-saved');
        $this->assertDatabaseCount('transactions', 3);
        $this->assertSame(29800.0, $account->fresh()->balance());
        $this->assertSame('6000.00', $receipt->fresh()->processReceipt->fees_amount);
        $this->assertSame($category->id, $receipt->fresh()->processReceipt->category_id);
        $client = $receipt->destinations()->where('kind', 'client')->sole();
        $commission = BrokerCommission::sole();
        $this->assertSame(2380000, $client->remainingCents());
        $this->assertSame(30000, $expense->fresh()->remainingCents());
        $this->assertSame(400.0, $advance->fresh()->remainingBalance());
        $this->assertSame(1200.0, $commission->remainingAmount());
        $this->assertTrue($commission->manual_advances);
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(30000, $report->viewData('totalIncome'));
        $this->assertEquals(3000, $report->viewData('ownIncome'));
        $this->assertEquals(200, $report->viewData('recoveredExpenses'));
        $this->assertEquals(26800, $report->viewData('thirdPartyIncome'));
        $this->assertEquals(3000, $report->viewData('byCategory')->get($category->name)['income']);
        Volt::test('banking.transactions.index')->set('category', $category->id)->assertSee('Somente minha receita');
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertSee('Despesas recuperadas')->assertSee('Passagem')->assertSee('Desfazer compensação');
        $statement = app(BrokerStatementService::class)->summary($broker);
        $this->assertEquals(1200, $statement['cash_out_period']);
        $this->assertTrue(app(BrokerStatementService::class)->entries($broker)->contains('type', 'Despesa de processos'));
    }

    public function test_unselected_and_future_advances_are_not_automatically_applied(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $advance = BrokerAdvance::create(['broker_id' => $broker->id, 'date' => now()->toDateString(), 'amount' => 100]);
        app(ProcessReceiptService::class)->save($receipt, $data);
        app(BrokerAdvanceService::class)->register(['broker_id' => $broker->id, 'date' => now()->toDateString(), 'amount' => 200, 'bank_account_id' => $account->id]);
        $commission = BrokerCommission::sole();
        $this->assertSame(0.0, $commission->settledAmount());
        $this->assertSame(100.0, $advance->fresh()->remainingBalance());
        $this->assertSame(0.0, app(BrokerCommissionService::class)->settleWithAdvances($commission));
    }

    public function test_expense_balance_can_be_used_across_receipts_and_cannot_be_used_twice(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $expense = $this->expense($broker, $account);
        $data['expenses'] = [$expense->id => '400'];
        app(ProcessReceiptService::class)->save($receipt, $data);
        $second = $receipt->replicate();
        $second->save();
        $data['expenses'] = [$expense->id => '200'];
        try {
            app(ProcessReceiptService::class)->save($second, $data);
            $this->fail('Overspending must fail');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('saldo suficiente', $e->getMessage());
        }
        $this->assertFalse($second->destinations()->exists());
        $this->assertDatabaseCount('broker_commissions', 1);
        $data['expenses'] = [$expense->id => '100'];
        app(ProcessReceiptService::class)->save($second, $data);
        $this->assertSame(0, $expense->fresh()->remainingCents());
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertSame(40000, $expense->fresh()->remainingCents());
        $this->assertNotNull($expense->fresh()->transaction);
        $this->assertNotNull($second->fresh());
    }

    public function test_wrong_broker_expenses_and_advances_are_rejected_atomically(): void
    {
        [$receipt, , $account, , $data] = $this->example();
        $other = Broker::create(['name' => 'Outro', 'status' => true]);
        $expense = $this->expense($other, $account);
        $advance = BrokerAdvance::create(['broker_id' => $other->id, 'amount' => 100, 'date' => now()->toDateString()]);
        foreach (['expenses' => $expense->id, 'advances' => $advance->id] as $field => $id) {
            $invalid = $data;
            $invalid[$field] = [$id => '10'];
            try {
                app(ProcessReceiptService::class)->save($receipt, $invalid);
                $this->fail('Wrong broker must fail');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('corretor', $e->getMessage());
            }
            $this->assertDatabaseCount('process_receipts', 0);
            $this->assertDatabaseCount('receipt_destinations', 0);
            $this->assertDatabaseCount('broker_commissions', 0);
        }
    }

    public function test_discounts_cannot_exceed_client_or_commission_and_category_must_be_income(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $expense = $this->expense($broker, $account, '25000.00');
        $advance = BrokerAdvance::create(['broker_id' => $broker->id, 'amount' => 2000, 'date' => now()->toDateString()]);
        foreach ([['expenses', $expense->id, '25000'], ['advances', $advance->id, '1600']] as [$field, $id, $amount]) {
            $invalid = $data;
            $invalid[$field] = [$id => $amount];
            try {
                app(ProcessReceiptService::class)->save($receipt, $invalid);
                $this->fail('Excess discount must fail');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('superar', $e->getMessage());
            }
            $this->assertDatabaseCount('process_receipts', 0);
        }
        $category = Category::create(['name' => 'Despesa', 'type' => 'expense', 'status' => true]);
        $data['category_id'] = $category->id;
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)->call('save')->assertHasErrors('category_id');
    }

    public function test_rounding_distributes_every_cent_and_modes_can_be_switched(): void
    {
        [$receipt, , , , $data] = $this->example();
        $component = Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)
            ->call('setMode', 'fees_mode', 'amount')->assertSet('form.fees', '6000.00')
            ->call('setMode', 'commission_mode', 'amount')->assertSet('form.broker_amount', '1500.00');
        $receipt->update(['amount' => '0.03']);
        $data['fees'] = '100';
        $data['broker_amount'] = '50';
        $data['office_amount'] = '50';
        $values = app(ProcessReceiptService::class)->calculate($receipt, $data);
        $this->assertSame(3, $values['broker'] + $values['office'] + $values['own']);
        $this->assertSame(0, $values['own']);
        app(ProcessReceiptService::class)->save($receipt, $data);
        $this->assertSame('0.00', $receipt->destinations()->where('kind', 'client')->sole()->amount);
    }

    public function test_expense_crud_updates_bank_cash_and_protects_recovered_records(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $component = Volt::test('brokers.process-expenses', ['brokerId' => $broker->id])
            ->call('create')->set('amount', '500.00')->set('description', 'Cartório')->set('accountId', $account->id)->call('save')->assertHasNoErrors()->assertSee('Cartório');
        $expense = BrokerProcessExpense::sole();
        $component->call('edit', $expense->id)->set('amount', '600.00')->call('save')->assertHasNoErrors();
        $this->assertSame('600.00', $expense->fresh()->transaction->amount);
        $data['expenses'] = [$expense->id => '100'];
        app(ProcessReceiptService::class)->save($receipt, $data);
        $component->call('delete', $expense->id)->assertHasErrors('expense');
        app(ProcessReceiptService::class)->undoRecovery($receipt, $expense->recoveries()->sole()->id);
        $component->call('delete', $expense->id)->assertHasNoErrors();
        $this->assertDatabaseCount('broker_process_expenses', 0);
        $this->assertSame(31000.0, $account->fresh()->balance());
    }

    public function test_undo_releases_offsets_and_cascade_deletion_preserves_original_expenses_and_advances(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $expense = $this->expense($broker, $account);
        $advance = app(BrokerAdvanceService::class)->register(['broker_id' => $broker->id, 'date' => now()->toDateString(), 'amount' => 700, 'bank_account_id' => $account->id])['advance'];
        $data['expenses'] = [$expense->id => '200'];
        $data['advances'] = [$advance->id => '300'];
        app(ProcessReceiptService::class)->save($receipt, $data);
        $commission = BrokerCommission::sole();
        app(ProcessReceiptService::class)->undoAdvance($receipt, $commission->settlements()->sole()->id);
        $this->assertSame(700.0, $advance->fresh()->remainingBalance());
        $client = $receipt->destinations()->where('kind', 'client')->sole();
        app(ReceiptDestinationService::class)->pay($client, '1000', now()->toDateString(), $account->id);
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertDatabaseCount('process_receipts', 0);
        $this->assertDatabaseCount('process_expense_recoveries', 0);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertSame(50000, $expense->fresh()->remainingCents());
        $this->assertSame(-200.0, $account->fresh()->balance());
    }

    public function test_manual_offsets_survive_payment_edits_and_protect_the_original_advance(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $advance = BrokerAdvance::create(['broker_id' => $broker->id, 'amount' => 700, 'date' => now()->toDateString()]);
        $data['advances'] = [$advance->id => '300'];
        app(ProcessReceiptService::class)->save($receipt, $data);
        $commission = BrokerCommission::sole();
        $service = app(BrokerCommissionService::class);
        $payment = $service->payAmount($commission, 100, now()->toDateString(), $account->id);
        $service->updatePayment($payment, 200, now()->toDateString(), $account->id);
        $this->assertSame(300.0, $commission->fresh()->settledAmount());
        $this->assertSame(1000.0, $commission->fresh()->remainingAmount());
        try {
            $service->updatePayment($payment, 1300, now()->toDateString(), $account->id);
            $this->fail('Payment exceeds net commission');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ultrapassar', $e->getMessage());
        }
        try {
            app(BrokerAdvanceService::class)->update($advance, ['date' => now()->toDateString(), 'amount' => 800]);
            $this->fail('Used advance must be protected');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Desfaça a compensação', $e->getMessage());
        }
        try {
            app(BrokerLedgerDeletionService::class)->deleteAdvance($advance);
            $this->fail('Used advance must be protected');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Desfaça a compensação', $e->getMessage());
        }
        $this->assertSame(400.0, $advance->fresh()->remainingBalance());
    }

    public function test_broker_profile_has_the_expense_tab_and_selection_changes_clear_discounts(): void
    {
        [$receipt, $broker, $account, , $data] = $this->example();
        $contact = Contact::create(['name' => $broker->name, 'type' => 'corretor', 'status' => true]);
        $broker->update(['contact_id' => $contact->id]);
        $expense = $this->expense($broker, $account);
        Volt::test('brokers.show', ['broker' => $contact])->set('records_tab', 'process_expenses')->assertSee('Despesas de processos')->assertSee('Passagem');
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)
            ->set('form.expenses.'.$expense->id, '100')->set('form.advances', [123 => '10'])
            ->set('form.broker_id', null)->assertSet('form.expenses', [])->assertSet('form.advances', []);
    }

    public function test_process_receipt_cannot_allocate_the_full_credit_to_revenue_categories(): void
    {
        [$receipt, , , $category, $data] = $this->example();
        app(ProcessReceiptService::class)->save($receipt, $data);
        Volt::test('banking.transactions.index')->call('edit', $receipt->id)->assertSet('editingProcessReceipt', true)->assertSet('formDestinateAfterSave', true);
        $this->expectException(\DomainException::class);
        app(TransactionAllocationService::class)->replace($receipt, [
            ['category_id' => $category->id, 'amount' => '10000.00'], ['category_id' => null, 'amount' => '20000.00'],
        ]);
    }
}

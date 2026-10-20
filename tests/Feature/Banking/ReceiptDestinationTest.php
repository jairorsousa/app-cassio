<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerLedgerDeletionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReceiptDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000]);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $case = CaseType::create(['name' => 'Caso', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'amount' => '32531.59', 'date' => now('America/Sao_Paulo')->toDateString(), 'description' => 'Recebimento de caso', 'bank_account_id' => $account->id, 'status' => 'settled', 'ofx_fitid' => 'receipt-imported']);
        $rows = [
            ['key' => 'a', 'kind' => 'client', 'beneficiary' => 'Cliente', 'amount' => '15387.44'],
            ['key' => 'b', 'kind' => 'broker', 'beneficiary' => '', 'amount' => '3147.43', 'broker_id' => $broker->id, 'case_type_id' => $case->id],
            ['key' => 'c', 'kind' => 'office', 'beneficiary' => 'Escritório', 'amount' => '6998.36'],
            ['key' => 'd', 'kind' => 'own', 'beneficiary' => '', 'amount' => '6998.36'],
        ];

        return [$receipt, $account, $broker, $case, $rows];
    }

    public function test_distribution_creates_one_pending_commission_and_keeps_the_original_receipt_and_balance(): void
    {
        [$receipt, $account, $broker, $case, $rows] = $this->example();
        $component = Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])
            ->set('rows', $rows)->call('save')->assertHasNoErrors()->assertSet('editing', false)
            ->assertSee('R$ 6.998,36')->assertDontSee('Registrar repasse')->assertDontSee('Pendente:');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('receipt_destinations', 4);
        $this->assertDatabaseCount('broker_commissions', 1);
        $commission = BrokerCommission::sole();
        $this->assertSame('3147.43', $commission->commission_amount);
        $this->assertSame('pending', $commission->status);
        $this->assertSame($broker->id, $commission->broker_id);
        $this->assertSame(33531.59, $account->fresh()->balance());
        $component->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('broker_commissions', 1);
        $this->assertDatabaseCount('receipt_destinations', 4);
        Volt::test('banking.transactions.index')->call('openDistribution', $receipt->id)->assertSet('distributingId', $receipt->id)->assertSee('Minha receita:');
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(32531.59, $report->viewData('totalIncome'));
        $this->assertEquals(6998.36, $report->viewData('ownIncome'));
        $this->assertEquals(25533.23, $report->viewData('thirdPartyIncome'));
    }

    public function test_partial_repasses_and_linked_imported_expenses_do_not_duplicate_movements(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $client = $receipt->destinations()->where('kind', 'client')->sole();
        $office = $receipt->destinations()->where('kind', 'office')->sole();
        $service->pay($client, '1000.00', now()->toDateString(), $account->id);
        $component = Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertViewHas('canEdit', false);
        $this->assertSame(1438744, $client->fresh()->remainingCents());
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(32531.59, $account->fresh()->balance());
        try {
            $service->pay($client, '20000.00', now()->toDateString(), $account->id);
            $this->fail('Repasse excessivo foi aceito.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('saldo', $e->getMessage());
        }
        $this->assertDatabaseCount('transactions', 2);

        $expense = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now()->toDateString(), 'amount' => '6998.36', 'description' => 'Pix ao escritório', 'bank_account_id' => $account->id, 'ofx_fitid' => 'expense-imported']);
        $before = $account->fresh()->balance();
        $service->pay($office, (string) $expense->amount, now()->toDateString(), $account->id, $expense->id);
        $this->assertSame(0, $office->fresh()->remainingCents());
        $this->assertDatabaseCount('transactions', 3);
        $this->assertSame($before, $account->fresh()->balance());
        $component->call('undoPayment', $office->payments()->sole()->id)->assertHasNoErrors();
        $this->assertNotNull($expense->fresh());
        $this->assertSame(699836, $office->fresh()->remainingCents());
        $component->call('undoPayment', $client->payments()->sole()->id)->assertHasNoErrors();
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertSame($before + 1000, $account->fresh()->balance());
    }

    public function test_broker_payments_are_shared_with_brokers_module_and_can_be_undone(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        app(ReceiptDestinationService::class)->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'broker')->sole();
        app(BrokerCommissionService::class)->payAmount($part->commission, 1000, now()->toDateString(), $account->id);
        $component = Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id]);
        $this->assertDatabaseCount('broker_commission_payments', 1);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(214743, $part->fresh()->remainingCents());
        $payment = app(BrokerCommissionService::class)->payAmount($part->commission, 2147.43);
        $this->assertSame(0, $part->fresh()->remainingCents());
        $this->assertSame('paid', $part->commission->fresh()->status);
        $component->call('undoBrokerPayment', $payment->id)->assertHasNoErrors();
        $this->assertSame(214743, $part->fresh()->remainingCents());
        $this->assertSame(32531.59, $account->fresh()->balance());
    }

    public function test_existing_commission_can_be_linked_and_cannot_be_used_twice(): void
    {
        [$receipt, $account, $broker, $case, $rows] = $this->example();
        $commission = app(BrokerCommissionService::class)->registerFixedAmount(['broker_id' => $broker->id, 'case_type_id' => $case->id, 'commission_amount' => 3147.43, 'reference_date' => now()->toDateString()]);
        $rows[1]['commission_id'] = $commission->id;
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $this->assertDatabaseCount('broker_commissions', 1);
        $other = Transaction::create(['type' => 'income', 'status' => 'settled', 'date' => now(), 'amount' => '32531.59', 'description' => 'Outro', 'bank_account_id' => $account->id]);
        Volt::test('banking.transactions.distribution', ['receiptId' => $other->id])->set('rows', $rows)->call('save')->assertHasErrors(['division']);
        $this->assertCount(0, $other->fresh()->destinations);
        $service->clear($receipt);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('broker_commissions', 1);
    }

    public function test_invalid_total_or_broker_rolls_back_and_receipt_and_commission_are_protected(): void
    {
        [$receipt, , , , $rows] = $this->example();
        $component = Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id]);
        $bad = $rows;
        $bad[3]['amount'] = '6998.35';
        $component->set('rows', $bad)->call('save')->assertHasErrors(['division']);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $bad = $rows;
        $bad[1]['case_type_id'] = null;
        $component->set('rows', $bad)->call('save')->assertHasErrors(['division']);
        $this->assertDatabaseCount('broker_commissions', 0);
        $component->set('rows', $rows)->call('save')->assertHasNoErrors();
        Volt::test('banking.transactions.index')->call('edit', $receipt->id)->set('formAmount', '100.00')->call('saveTransaction')->assertHasErrors(['transaction']);
        foreach ([
            fn () => app(TransactionService::class)->delete($receipt),
            fn () => app(BrokerLedgerDeletionService::class)->deleteCommission(BrokerCommission::sole()),
        ] as $action) {
            try {
                $action();
                $this->fail('Registro vinculado foi excluído.');
            } catch (\DomainException) {
                $this->assertSame('32531.59', $receipt->fresh()->amount);
            }
        }
        $component->call('clear')->assertHasNoErrors();
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_new_receipt_can_open_distribution_after_saving_and_pending_receipts_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'BB']);
        $component = Volt::test('banking.transactions.index')->call('create', 'income')
            ->set('formAmount', '1250.00')->set('formDescription', 'Novo recebimento')
            ->set('formDestinateAfterSave', true)->call('saveTransaction')->assertHasErrors(['formBankAccountId']);
        $component->set('formBankAccountId', $account->id)->set('formStatus', 'pending')->call('saveTransaction')->assertHasErrors(['formStatus']);
        $this->assertDatabaseCount('transactions', 0);
        $component->set('formStatus', 'settled')->call('saveTransaction')->assertHasNoErrors()->assertSet('showFormModal', false)->assertSet('distributingId', Transaction::sole()->id)->assertSee('Recebimento de processo');
    }

    public function test_existing_broker_advances_are_compensated_without_creating_another_cash_expense(): void
    {
        [$receipt, $account, $broker, , $rows] = $this->example();
        app(BrokerAdvanceService::class)->register([
            'broker_id' => $broker->id, 'amount' => 1000, 'date' => now()->toDateString(), 'bank_account_id' => $account->id,
        ]);
        app(ReceiptDestinationService::class)->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'broker')->sole();
        $this->assertSame(100000, $part->paidCents());
        $this->assertSame(214743, $part->remainingCents());
        $this->assertDatabaseCount('transactions', 2);
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertViewHas('canEdit', false)->call('clear')->assertHasErrors(['division']);
    }

    public function test_linked_expense_cannot_be_edited_deleted_or_used_for_another_part(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $client = $receipt->destinations()->where('kind', 'client')->sole();
        $office = $receipt->destinations()->where('kind', 'office')->sole();
        $expense = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now(), 'amount' => '1000.00', 'description' => 'Saída importada', 'bank_account_id' => $account->id]);
        $service->pay($client, '1000.00', now()->toDateString(), $account->id, $expense->id);
        foreach ([
            fn () => $service->pay($office, '1000.00', now()->toDateString(), $account->id, $expense->id),
            fn () => app(TransactionService::class)->update($expense, ['amount' => '2000.00']),
            fn () => app(TransactionService::class)->delete($expense),
            fn () => $service->clear($receipt),
        ] as $action) {
            try {
                $action();
                $this->fail('Alteração incompatível com repasse foi aceita.');
            } catch (\DomainException) {
                $this->assertSame('1000.00', $expense->fresh()->amount);
            }
        }
        $this->assertSame(1, $client->payments()->count());
        $this->assertSame(0, $office->payments()->count());
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_imported_broker_payment_links_to_commission_and_can_be_unlinked_from_brokers(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'broker')->sole();
        $expense = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now(), 'amount' => '3147.43', 'description' => 'Pix ao corretor', 'bank_account_id' => $account->id, 'ofx_fitid' => 'broker-ofx']);
        $balance = $account->fresh()->balance();
        $service->pay($part, (string) $expense->amount, now()->toDateString(), $account->id, $expense->id);
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertSee('Saída vinculada ao corretor');
        $this->assertSame(0, $part->fresh()->remainingCents());
        $this->assertSame('paid', $part->commission->fresh()->status);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame($balance, $account->fresh()->balance());
        app(BrokerLedgerDeletionService::class)->deletePayment($part->commission->payments()->sole());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertDatabaseCount('broker_commission_payments', 0);
        $this->assertSame(314743, $part->fresh()->remainingCents());
        $this->assertFalse($expense->fresh()->trashed());
        $this->assertSame($balance, $account->fresh()->balance());
        $service->pay($part, '3147.43', now()->toDateString(), $account->id, $expense->id);
        $service->undo($part->payments()->sole());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertDatabaseCount('broker_commission_payments', 0);
        $this->assertFalse($expense->fresh()->trashed());
    }

    public function test_receipt_deletion_only_occurs_after_the_single_confirmation_and_removes_destinations(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        app(ReceiptDestinationService::class)->replace($receipt, $rows);
        $component = Volt::test('banking.transactions.index')
            ->call('delete', $receipt->id)->assertSet('deletingId', $receipt->id)
            ->assertSee('todas as divisões vinculadas também serão excluídas')
            ->assertSee('Excluir lançamento e divisões');
        $this->assertFalse($receipt->fresh()->trashed());
        $this->assertDatabaseCount('receipt_destinations', 4);
        $component->call('cancelDeletion')->assertSet('deletingId', null);
        $this->assertFalse($receipt->fresh()->trashed());
        $component->call('delete', $receipt->id)->call('confirmDeletion')
            ->assertHasNoErrors()->assertSet('deletingId', null);
        $this->assertSoftDeleted('transactions', ['id' => $receipt->id]);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertSame(1000.0, $account->fresh()->balance());
        $component->call('confirmDeletion')->assertHasNoErrors();
    }

    public function test_cascade_deletion_removes_generated_repasses_and_releases_advances_but_keeps_imported_expenses(): void
    {
        [$receipt, $account, $broker, , $rows] = $this->example();
        BrokerAdvance::create([
            'broker_id' => $broker->id, 'amount' => 1000, 'date' => now(), 'bank_account_id' => $account->id,
        ]);
        $advance = BrokerAdvance::sole();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $client = $receipt->destinations()->where('kind', 'client')->sole();
        $brokerPart = $receipt->destinations()->where('kind', 'broker')->sole();
        $office = $receipt->destinations()->where('kind', 'office')->sole();
        $service->pay($client, '100.00', now()->toDateString(), $account->id);
        $service->pay($brokerPart, '100.00', now()->toDateString(), $account->id);
        $generatedClient = $client->payments()->sole()->transaction;
        $generatedBroker = $brokerPart->commission->payments()->sole()->transaction;
        $imported = [];
        foreach ([[$office, '200.00'], [$brokerPart, '100.00']] as [$part, $amount]) {
            $expense = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now(), 'amount' => $amount, 'description' => 'Pix importado', 'bank_account_id' => $account->id, 'ofx_fitid' => 'linked-'.$part->id]);
            $service->pay($part, $amount, now()->toDateString(), $account->id, $expense->id);
            $imported[] = $expense;
        }
        $this->assertSame(1000.0, $brokerPart->commission->settledAmount());
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertSoftDeleted('transactions', ['id' => $receipt->id]);
        $this->assertSoftDeleted('transactions', ['id' => $generatedClient->id]);
        $this->assertSoftDeleted('transactions', ['id' => $generatedBroker->id]);
        foreach ($imported as $expense) {
            $this->assertFalse($expense->fresh()->trashed());
        }
        $this->assertSame(700.0, $account->fresh()->balance());
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertDatabaseCount('broker_commission_payments', 0);
        $this->assertDatabaseCount('broker_commission_settlements', 0);
        $this->assertSame(1000.0, $advance->fresh()->remainingBalance());
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(0, $report->viewData('thirdPartyIncome'));
        $this->assertEquals(0, $report->viewData('pendingRepasses'));
    }

    public function test_receipt_deletion_preserves_a_preexisting_commission_and_its_payment_history(): void
    {
        [$receipt, $account, $broker, $case, $rows] = $this->example();
        $commissions = app(BrokerCommissionService::class);
        $commission = $commissions->registerFixedAmount(['broker_id' => $broker->id, 'case_type_id' => $case->id, 'commission_amount' => 3147.43, 'reference_date' => now()->toDateString(), 'bank_account_id' => $account->id]);
        $payment = $commissions->payAmount($commission, 100);
        $rows[1]['commission_id'] = $commission->id;
        app(ReceiptDestinationService::class)->replace($receipt, $rows);
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertNotNull($commission->fresh());
        $this->assertNotNull($payment->fresh());
        $this->assertFalse($payment->transaction->fresh()->trashed());
        $this->assertSame('partially_paid', $commission->fresh()->status);
        $this->assertSame(900.0, $account->fresh()->balance());
    }

    public function test_deleting_a_linked_expense_reopens_only_its_repasse_and_keeps_the_receipt(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'broker')->sole();
        $expense = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now(), 'amount' => '1000.00', 'description' => 'Pix ao corretor', 'bank_account_id' => $account->id]);
        $service->pay($part, '1000.00', now()->toDateString(), $account->id, $expense->id);
        Volt::test('banking.transactions.index')->call('delete', $expense->id)
            ->assertSee('O valor voltará a ficar pendente de repasse')
            ->call('confirmDeletion')->assertHasNoErrors();
        $this->assertFalse($receipt->fresh()->trashed());
        $this->assertSoftDeleted('transactions', ['id' => $expense->id]);
        $this->assertDatabaseCount('receipt_destinations', 4);
        $this->assertSame(314743, $part->fresh()->remainingCents());
        $this->assertDatabaseCount('broker_commission_payments', 0);
    }

    public function test_generated_client_repasse_can_be_deleted_after_confirmation(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $service->pay($part, '1000.00', now()->toDateString(), $account->id);
        $expense = $part->payments()->sole()->transaction;
        Volt::test('banking.transactions.index')->call('delete', $expense->id)
            ->assertSet('deletingId', $expense->id)->call('confirmDeletion')->assertHasNoErrors();
        $this->assertSoftDeleted('transactions', ['id' => $expense->id]);
        $this->assertFalse($receipt->fresh()->trashed());
        $this->assertSame(1538744, $part->fresh()->remainingCents());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
    }

    public function test_failed_deletion_rolls_back_the_receipt_destinations_and_repasses_together(): void
    {
        [$receipt, $account, , , $rows] = $this->example();
        $service = app(ReceiptDestinationService::class);
        $service->replace($receipt, $rows);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $service->pay($part, '1000.00', now()->toDateString(), $account->id);
        $expense = $part->payments()->sole()->transaction;
        $balance = $account->fresh()->balance();
        $dispatcher = Transaction::getEventDispatcher();
        Transaction::setEventDispatcher(clone $dispatcher);
        Transaction::deleting(function ($transaction) use ($receipt) {
            if ($transaction->id === $receipt->id) {
                throw new \RuntimeException('Falha simulada ao excluir recebimento.');
            }
        });
        try {
            app(TransactionService::class)->deleteWithDestinations($receipt);
            $this->fail('Falha de exclusão não foi propagada.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha simulada ao excluir recebimento.', $e->getMessage());
        } finally {
            Transaction::setEventDispatcher($dispatcher);
        }
        $this->assertFalse($receipt->fresh()->trashed());
        $this->assertFalse($expense->fresh()->trashed());
        $this->assertDatabaseCount('receipt_destinations', 4);
        $this->assertDatabaseCount('broker_commissions', 1);
        $this->assertDatabaseCount('receipt_destination_payments', 1);
        $this->assertSame($balance, $account->fresh()->balance());
    }
}

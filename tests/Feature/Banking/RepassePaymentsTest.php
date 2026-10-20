<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptAccountAllocationService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RepassePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $bank = BankAccount::create(['name' => 'Bradesco', 'type' => 'checking', 'initial_balance' => 0]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
        $office = BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse']);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'amount' => '10000.00', 'description' => 'Recebimento exemplo', 'date' => now()->subDay()->toDateString(), 'status' => 'settled', 'bank_account_id' => $bank->id, 'ofx_fitid' => 'original-bb-001']);
        app(ProcessReceiptService::class)->save($receipt, [
            'broker_id' => $broker->id, 'client_name' => 'Maria ou processo', 'fees_mode' => 'amount', 'fees' => '3000',
            'commission_mode' => 'amount', 'broker_amount' => '1000', 'office_amount' => '1000', 'expenses' => [], 'advances' => [],
        ]);

        return [$receipt, $bank, $client, $office];
    }

    public function test_users_example_supports_partial_client_and_office_payments_until_zero(): void
    {
        [$receipt, $bank, $client, $office] = $this->example();
        $clientPart = $receipt->destinations()->where('kind', 'client')->sole();
        $officePart = $receipt->destinations()->where('kind', 'office')->sole();
        $this->assertSame(10000.0, $bank->balance());
        $this->assertSame(-7000.0, $client->balance());
        $this->assertSame(-1000.0, $office->balance());
        $this->assertSame(1, Transaction::where('bank_account_id', $bank->id)->count());
        $this->assertSame('1000.00', BrokerCommission::sole()->commission_amount);
        $component = Volt::test('banking.accounts.repasses', ['accountId' => $client->id]);
        $component->assertSee('Maria ou processo')->call('startPayment', $clientPart->id)
            ->assertSet('bankId', $bank->id)->assertSet('amount', '7000.00')
            ->set('amount', '2000.25')->call('savePayment')->assertHasNoErrors()->assertSee('4.999,75');
        $this->assertSame(7999.75, $bank->fresh()->balance());
        $this->assertSame(-4999.75, $client->fresh()->balance());
        $component->call('startPayment', $clientPart->id)->set('amount', '4999.75')->call('savePayment')->assertHasNoErrors()->assertSee('Repassado');
        $this->assertSame(3000.0, $bank->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $officeComponent = Volt::test('banking.accounts.repasses', ['accountId' => $office->id]);
        $officeComponent->call('startPayment', $officePart->id)->set('amount', '400')->call('savePayment')->assertHasNoErrors();
        $this->assertSame(-600.0, $office->fresh()->balance());
        $officeComponent->call('startPayment', $officePart->id)->call('savePayment')->assertHasNoErrors();
        $this->assertSame(2000.0, $bank->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        app(BrokerCommissionService::class)->payAmount(BrokerCommission::sole(), 1000, now()->toDateString(), $bank->id);
        $this->assertSame(1000.0, $bank->fresh()->balance());
        $this->assertSame('10000.00', $receipt->fresh()->amount);
        $this->assertSame('original-bb-001', $receipt->fresh()->ofx_fitid);
        $this->assertSame(1, Transaction::where('type', 'income')->count());
        $this->assertSame(0, $clientPart->fresh()->remainingCents());
        $this->assertSame(0, $officePart->fresh()->remainingCents());
    }

    public function test_overpayments_invalid_amounts_dates_and_virtual_bank_sources_leave_balances_unchanged(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $service = app(ReceiptDestinationService::class);
        foreach (['7000.01', '0', '-1', '1.001', '1,00'] as $amount) {
            try {
                $service->pay($part, $amount, now()->toDateString(), $bank->id);
                $this->fail('Invalid payment must be rejected');
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        foreach ([now()->addDay()->toDateString(), now()->subDays(2)->toDateString(), 'invalid'] as $date) {
            try {
                $service->pay($part, '10', $date, $bank->id);
                $this->fail('Invalid date must be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('date', $e->errors());
            }
        }
        Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('startPayment', $part->id)
            ->set('bankId', $client->id)->set('amount', '100')->call('savePayment')->assertHasErrors('payment');
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertSame(3, Transaction::count());
    }

    public function test_repeated_payment_submission_checks_remaining_balance_and_cannot_overpay(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $first = Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('startPayment', $part->id)->set('amount', '4000');
        $second = Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('startPayment', $part->id)->set('amount', '4000');
        $first->call('savePayment')->assertHasNoErrors();
        $second->call('savePayment')->assertHasErrors('payment');
        $this->assertSame(6000.0, $bank->fresh()->balance());
        $this->assertSame(-3000.0, $client->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 1);
    }

    public function test_payment_can_come_from_a_different_bank_and_undo_restores_both_balances(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $otherBank = BankAccount::create(['name' => 'Itaú', 'initial_balance' => 500]);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        app(ReceiptDestinationService::class)->pay($part, '100.01', now()->toDateString(), $otherBank->id);
        $payment = $part->payments()->sole();
        $this->assertSame(399.99, $otherBank->fresh()->balance());
        $this->assertSame(-6899.99, $client->fresh()->balance());
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame($payment->transaction_id, $payment->settlementTransaction->related_transaction_id);
        $out = $payment->transaction;
        $in = $payment->settlementTransaction;
        Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('undoPayment', $payment->id)->assertHasNoErrors();
        $this->assertSame(500.0, $otherBank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $this->assertSoftDeleted($out);
        $this->assertSoftDeleted($in);
        $this->assertDatabaseCount('receipt_destination_payments', 0);
    }

    public function test_deleting_generated_payment_or_receipt_reverses_linked_movements(): void
    {
        [$receipt, $bank, $client, $office] = $this->example();
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $service = app(ReceiptDestinationService::class);
        $service->pay($part, '10', now()->toDateString(), $bank->id);
        app(TransactionService::class)->deleteWithDestinations($part->payments()->sole()->transaction);
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $service->pay($part, '2000', now()->toDateString(), $bank->id);
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertSame(0.0, $bank->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $this->assertSame(0, Transaction::count());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
    }

    public function test_linking_an_imported_expense_settles_without_another_bank_debit_and_unlink_preserves_it(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $expense = Transaction::create(['type' => 'expense', 'amount' => '1500', 'date' => now()->toDateString(), 'status' => 'settled', 'description' => 'PIX importado', 'bank_account_id' => $bank->id, 'ofx_fitid' => 'expense-fitid']);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        app(ReceiptDestinationService::class)->pay($part, '0', now()->toDateString(), $bank->id, $expense->id);
        $this->assertSame(8500.0, $bank->fresh()->balance());
        $this->assertSame(-5500.0, $client->fresh()->balance());
        $payment = $part->payments()->sole();
        $this->assertFalse($payment->transaction_created);
        app(ReceiptDestinationService::class)->undo($payment);
        $this->assertSame(8500.0, $bank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $this->assertSame('expense-fitid', $expense->fresh()->ofx_fitid);
        $this->assertNull($expense->fresh()->related_transaction_id);
    }

    public function test_edit_and_clear_are_blocked_while_a_partial_payment_exists(): void
    {
        [$receipt, $bank] = $this->example();
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $service = app(ReceiptDestinationService::class);
        $service->pay($part, '1', now()->toDateString(), $bank->id);
        try {
            $service->clear($receipt);
            $this->fail('Cannot erase a paid division');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Desfaça', $e->getMessage());
        }
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertDontSee('Editar divisão');
        $service->undo($part->payments()->sole());
        $service->clear($receipt);
        $this->assertSame(10000.0, $bank->fresh()->balance());
    }

    public function test_failure_while_crediting_virtual_account_rolls_back_the_bank_payment(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $dispatcher = Transaction::getEventDispatcher();
        Transaction::setEventDispatcher(clone $dispatcher);
        Transaction::creating(function (Transaction $transaction) use ($client) {
            if ($transaction->bank_account_id === $client->id && $transaction->source_type === ReceiptDestinationPayment::class) {
                throw new \RuntimeException('Falha no crédito virtual');
            }
        });
        try {
            app(ReceiptDestinationService::class)->pay($receipt->destinations()->where('kind', 'client')->sole(), '1000', now()->toDateString(), $bank->id);
            $this->fail('Failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha no crédito virtual', $e->getMessage());
        } finally {
            Transaction::setEventDispatcher($dispatcher);
        }
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertSame(3, Transaction::count());
    }

    public function test_payment_actions_are_scoped_to_the_open_virtual_account(): void
    {
        [$receipt, $bank, $client, $office] = $this->example();
        $officePart = $receipt->destinations()->where('kind', 'office')->sole();
        app(ReceiptDestinationService::class)->pay($officePart, '100', now()->toDateString(), $bank->id);
        foreach ([['startPayment', $officePart->id], ['undoPayment', $officePart->payments()->sole()->id]] as [$action, $id]) {
            try {
                Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call($action, $id);
                $this->fail('Payment from another virtual account must be rejected');
            } catch (ModelNotFoundException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame(-900.0, $office->fresh()->balance());
        Volt::test('banking.accounts.index')->call('manageRepasses', $client->id)->assertSee('Repasses · Cliente')->call('closeRepasses')->assertSet('repasseAccountId', null);
    }

    public function test_virtual_obligations_do_not_appear_as_cash_outflows_and_payments_do_not_add_expenses(): void
    {
        [$receipt, $bank, $client] = $this->example();
        app(ReceiptDestinationService::class)->pay($receipt->destinations()->where('kind', 'client')->sole(), '2000', now()->toDateString(), $bank->id);
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertSame(['in' => 10000.0, 'out' => 2000.0], $report->viewData('byAccount')->get('Bradesco'));
        $this->assertFalse($report->viewData('byAccount')->has('Cliente'));
        $this->assertFalse($report->viewData('byAccount')->has('Mota e Silva Advogados'));
        $this->assertEquals(10000, $report->viewData('totalIncome'));
        $this->assertEquals(1000, $report->viewData('ownIncome'));
        $this->assertEquals(0, $report->viewData('totalExpense'));
        Livewire::withoutLazyLoading();
        $dashboard = Volt::test('banking.dashboard');
        $this->assertEquals(8000, $dashboard->viewData('totalBalance'));
        $this->assertEquals(-6000, $dashboard->viewData('repasseBalance'));
    }

    public function test_migration_converts_old_positive_transfers_and_prior_payments_without_duplicating_bank_cash(): void
    {
        [$receipt, $bank, $client, $office] = $this->example();
        $service = app(ReceiptAccountAllocationService::class);
        // Reproduce the previous deployed flow, including its existing payment history.
        foreach ($receipt->destinations()->whereNotNull('repasse_account_id')->get() as $part) {
            $target = $part->repasseAccount;
            $service->remove($part);
            $out = app(TransferService::class)->execute($bank, $target, (float) $part->amount, $receipt->date);
            $out->update(['source_type' => ReceiptDestination::class, 'source_id' => $part->id]);
            $out->related->update(['source_type' => ReceiptDestination::class, 'source_id' => $part->id]);
            $part->update(['allocation_transaction_id' => $out->id]);
        }
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $payment = $part->payments()->create(['transaction_created' => true]);
        $expense = Transaction::create([
            'type' => 'expense', 'amount' => '500.00', 'status' => 'settled', 'date' => now()->toDateString(),
            'description' => 'Repasse anterior', 'bank_account_id' => $bank->id,
            'source_type' => ReceiptDestinationPayment::class, 'source_id' => $payment->id,
        ]);
        $payment->update(['transaction_id' => $expense->id]);
        $manual = app(TransferService::class)->execute($bank, $client, 50, now()->toDateString());
        $this->assertSame(1450.0, $bank->fresh()->balance());
        $migration = require database_path('migrations/2026_10_20_040000_convert_repasse_balances_to_obligations.php');
        $migration->up();
        $this->assertSame(9450.0, $bank->fresh()->balance());
        $this->assertSame(-6450.0, $client->fresh()->balance());
        $this->assertSame(-1000.0, $office->fresh()->balance());
        $this->assertSame(650000, $part->fresh()->remainingCents());
        $this->assertNotNull($manual->fresh());
        $this->assertNotNull($expense->fresh());
        $this->assertNotNull($payment->fresh()->settlement_transaction_id);
        $this->assertSame('original-bb-001', $receipt->fresh()->ofx_fitid);
        $count = Transaction::count();
        $migration->up(); // A retry after a deployment interruption is safe.
        $this->assertSame($count, Transaction::count());
        $this->assertSame(9450.0, $bank->fresh()->balance());
        $this->assertSame(-6450.0, $client->fresh()->balance());
        app(ReceiptDestinationService::class)->undo($payment->fresh());
        $this->assertSame(9950.0, $bank->fresh()->balance());
        $this->assertSame(-6950.0, $client->fresh()->balance());
    }

    public function test_retry_of_the_same_confirmation_does_not_duplicate_a_partial_payment(): void
    {
        [$receipt, $bank, $client] = $this->example();
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $key = (string) Str::uuid();
        $service = app(ReceiptDestinationService::class);
        $service->pay($part, '1000', now()->toDateString(), $bank->id, requestKey: $key);
        $service->pay($part, '1000', now()->toDateString(), $bank->id, requestKey: $key);
        $this->assertDatabaseCount('receipt_destination_payments', 1);
        $this->assertSame(9000.0, $bank->fresh()->balance());
        $this->assertSame(-6000.0, $client->fresh()->balance());
        // A new confirmation permits a legitimate second payment of the same amount.
        $service->pay($part, '1000', now()->toDateString(), $bank->id, requestKey: (string) Str::uuid());
        $this->assertDatabaseCount('receipt_destination_payments', 2);
        $this->assertSame(8000.0, $bank->fresh()->balance());
        $this->assertSame(-5000.0, $client->fresh()->balance());
    }
}

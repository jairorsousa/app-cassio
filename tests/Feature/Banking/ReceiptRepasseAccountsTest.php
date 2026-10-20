<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReceiptRepasseAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $source = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
        $office = BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse']);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => '30000.00', 'description' => 'Recebimento', 'status' => 'settled', 'bank_account_id' => $source->id]);
        $data = ['broker_id' => $broker->id, 'client_name' => 'Cliente ou processo', 'fees_mode' => 'percent', 'fees' => '20', 'commission_mode' => 'percent', 'broker_amount' => '25', 'office_amount' => '25', 'category_id' => null, 'expenses' => [], 'advances' => []];

        return [$receipt, $source, $client, $office, $broker, $data];
    }

    public function test_client_and_fixed_office_are_transferred_without_inflating_income_or_expenses(): void
    {
        [$receipt, $source, $client, $office, , $data] = $this->example();
        $data['office_name'] = 'Outro escritório enviado por uma tela antiga';
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)
            ->assertDontSee('Nome do escritório')->assertSee('Mota e Silva Advogados')->call('save')->assertHasNoErrors();
        $this->assertSame(5500.0, $source->fresh()->balance());
        $this->assertSame(24000.0, $client->fresh()->balance());
        $this->assertSame(1500.0, $office->fresh()->balance());
        $this->assertSame(31000.0, BankAccount::all()->sum(fn ($account) => $account->balance()));
        $this->assertSame(4, Transaction::where('type', 'transfer')->count());
        $this->assertSame(1, Transaction::where('type', 'income')->count());
        $this->assertSame(0, Transaction::where('type', 'expense')->count());
        $this->assertSame('Mota e Silva Advogados', $receipt->destinations()->where('kind', 'office')->sole()->beneficiary);
        $this->assertEquals(0, Transaction::where('type', 'transfer')->sum('amount'));
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(30000, $report->viewData('totalIncome'));
        $this->assertEquals(0, $report->viewData('totalExpense'));
        $this->assertEquals(3000, $report->viewData('ownIncome'));
        Volt::test('banking.transactions.index')->set('account', $client->id)->assertSee('Distribuição entre contas')->assertSee('Ver recebimento');
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertSee('Conta de repasse:')->assertDontSee('Registrar repasse')->assertDontSee('Pendente:');
    }

    public function test_recovered_expenses_reduce_the_client_credit_and_undo_updates_both_sides(): void
    {
        [$receipt, $source, $client, $office, $broker, $data] = $this->example();
        $expense = app(BrokerProcessExpenseService::class)->save($broker, ['date' => now()->toDateString(), 'amount' => '500', 'description' => 'Passagem', 'bank_account_id' => $source->id]);
        $data['expenses'] = [$expense->id => '500'];
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        $this->assertSame(23500.0, $client->fresh()->balance());
        $this->assertSame(5500.0, $source->fresh()->balance());
        $this->assertSame(1500.0, $office->fresh()->balance());
        $service->undoRecovery($receipt, $expense->recoveries()->sole()->id);
        $this->assertSame(24000.0, $client->fresh()->balance());
        $this->assertSame(5000.0, $source->fresh()->balance());
        $this->assertSame(4, Transaction::where('type', 'transfer')->count());
        $this->assertSame(1, Transaction::where('type', 'expense')->count());
        $this->assertSame(50000, $expense->fresh()->remainingCents());
    }

    public function test_reediting_replaces_allocations_without_duplicate_active_transfers(): void
    {
        [$receipt, $source, $client, $office, , $data] = $this->example();
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        $service->save($receipt, $data);
        $this->assertSame(5500.0, $source->fresh()->balance());
        $this->assertSame(24000.0, $client->fresh()->balance());
        $this->assertSame(1500.0, $office->fresh()->balance());
        $data['fees'] = '30';
        $service->save($receipt, $data);
        $this->assertSame(7750.0, $source->fresh()->balance());
        $this->assertSame(21000.0, $client->fresh()->balance());
        $this->assertSame(2250.0, $office->fresh()->balance());
        $this->assertSame(4, Transaction::where('type', 'transfer')->count());
        $this->assertDatabaseCount('broker_commissions', 1);
    }

    public function test_clearing_and_deleting_reverse_generated_transfers_but_preserve_unrelated_entries(): void
    {
        [$receipt, $source, $client, $office, , $data] = $this->example();
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        app(ReceiptDestinationService::class)->clear($receipt);
        $this->assertSame(31000.0, $source->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $this->assertSame(0, Transaction::where('type', 'transfer')->count());
        $service->save($receipt, $data);
        $manual = app(TransferService::class)->execute($source, $client, 100, now()->toDateString());
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertSame(900.0, $source->fresh()->balance());
        $this->assertSame(100.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $this->assertNotNull($manual->fresh());
        $this->assertSame(2, Transaction::where('type', 'transfer')->count());
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('process_receipts', 0);
    }

    public function test_missing_ambiguous_or_inactive_targets_fail_without_partial_financial_writes(): void
    {
        [$receipt, $source, $client, $office, , $data] = $this->example();
        $service = app(ProcessReceiptService::class);
        foreach (['checking', 'inactive', 'duplicate'] as $scenario) {
            $duplicate = null;
            $client->update(['type' => $scenario === 'checking' ? 'checking' : 'repasse', 'status' => $scenario !== 'inactive']);
            if ($scenario === 'duplicate') {
                $duplicate = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
            }
            try {
                $service->save($receipt, $data);
                $this->fail('Invalid target must be rejected');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Cliente', $e->getMessage());
            }
            $this->assertDatabaseCount('process_receipts', 0);
            $this->assertDatabaseCount('receipt_destinations', 0);
            $this->assertDatabaseCount('broker_commissions', 0);
            $this->assertSame(1, Transaction::count());
            $duplicate?->delete();
        }
        $client->update(['type' => 'repasse', 'status' => true]);
        $service->save($receipt, $data);
        $office->update(['status' => false]);
        try {
            $service->save($receipt, $data);
            $this->fail('Assigned inactive target must be rejected');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ativa', $e->getMessage());
        }
        $this->assertSame(5500.0, $source->fresh()->balance());
        $this->assertSame(4, Transaction::where('type', 'transfer')->count());
    }

    public function test_assigned_account_can_be_renamed_and_keeps_its_identity(): void
    {
        [$receipt, , $client, , , $data] = $this->example();
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        $client->update(['name' => 'Valores de clientes']);
        $service->save($receipt, $data);
        $this->assertSame($client->id, $receipt->destinations()->where('kind', 'client')->sole()->repasse_account_id);
        $this->assertSame(24000.0, $client->fresh()->balance());
    }

    public function test_fully_recovered_client_share_creates_no_zero_transfer_and_can_be_restored(): void
    {
        [$receipt, $source, $client, , $broker, $data] = $this->example();
        $expense = app(BrokerProcessExpenseService::class)->save($broker, ['date' => now()->toDateString(), 'amount' => '24000', 'description' => 'Custos recuperados', 'bank_account_id' => $source->id]);
        $data['expenses'] = [$expense->id => '24000'];
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $this->assertNull($part->allocation_transaction_id);
        $this->assertSame($client->id, $part->repasse_account_id);
        $this->assertSame(2, Transaction::where('type', 'transfer')->count());
        $service->undoRecovery($receipt, $expense->recoveries()->sole()->id);
        $this->assertSame(24000.0, $client->fresh()->balance());
        $this->assertSame(4, Transaction::where('type', 'transfer')->count());
    }

    public function test_mid_allocation_failure_rolls_back_all_generated_records(): void
    {
        [$receipt, $source, $client, $office, , $data] = $this->example();
        $dispatcher = Transaction::getEventDispatcher();
        Transaction::setEventDispatcher(clone $dispatcher);
        Transaction::creating(function (Transaction $transaction) use ($office) {
            if ($transaction->type === 'transfer' && $transaction->bank_account_id === $office->id) {
                throw new \RuntimeException('Falha simulada');
            }
        });
        try {
            app(ProcessReceiptService::class)->save($receipt, $data);
            $this->fail('Failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha simulada', $e->getMessage());
        } finally {
            Transaction::setEventDispatcher($dispatcher);
        }
        $this->assertSame(1, Transaction::count());
        $this->assertSame(31000.0, $source->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertDatabaseCount('process_receipts', 0);
        $this->assertDatabaseCount('broker_commissions', 0);
    }

    public function test_generated_transfers_and_linked_account_types_cannot_be_changed_in_isolation(): void
    {
        [$receipt, , $client, , , $data] = $this->example();
        app(ProcessReceiptService::class)->save($receipt, $data);
        $transfer = Transaction::where('source_type', ReceiptDestination::class)->firstOrFail();
        try {
            app(TransactionService::class)->deleteWithDestinations($transfer);
            $this->fail('Generated transfer must be protected');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('somente leitura', $e->getMessage());
        }
        try {
            app(TransactionService::class)->update($receipt, ['date' => now()->subDay()->toDateString()]);
            $this->fail('Receipt date must stay aligned');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('data', $e->getMessage());
        }
        Volt::test('banking.accounts.index')->call('edit', $client->id)->set('type', 'checking')->call('save')->assertHasErrors('type');
        Volt::test('banking.accounts.index')->call('delete', $client->id);
        $this->assertNotNull($client->fresh());
        $this->assertSame('repasse', $client->fresh()->type);
    }

    public function test_account_modal_supports_repasse_and_renames_only_the_cash_label(): void
    {
        $this->actingAs(User::factory()->create());
        $cash = BankAccount::create(['name' => 'Carteira', 'type' => 'cash', 'initial_balance' => 100]);
        Volt::test('banking.accounts.index')->call('create')->assertSee('Dinheiro')->assertSee('Repasse')
            ->set('name', 'Cliente')->set('type', 'repasse')->set('initial_balance', '0')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('bank_accounts', ['name' => 'Cliente', 'type' => 'repasse']);
        Volt::test('banking.accounts.index')->call('edit', $cash->id)->assertSet('type', 'cash')->assertSee('Dinheiro')->call('save')->assertHasNoErrors();
        $this->assertSame('cash', $cash->fresh()->type);
        $this->assertSame(100.0, $cash->fresh()->balance());
    }
}

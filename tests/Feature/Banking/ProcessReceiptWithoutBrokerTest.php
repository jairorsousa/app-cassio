<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\ProcessReceipt;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProcessReceiptWithoutBrokerTest extends TestCase
{
    use RefreshDatabase;

    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $source = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
        $office = BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse']);
        $category = Category::create(['name' => 'Honorários próprios', 'type' => 'income', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => '30000.00', 'description' => 'Recebimento sem corretor', 'status' => 'settled', 'bank_account_id' => $source->id]);
        $data = ['broker_id' => null, 'client_name' => 'Cliente ou processo', 'fees_mode' => 'percent', 'fees' => '20', 'commission_mode' => 'percent', 'broker_amount' => '0', 'office_amount' => '40', 'category_id' => $category->id, 'expenses' => [], 'advances' => []];

        return [$receipt, $source, $client, $office, $data];
    }

    public function test_optional_broker_splits_fees_only_between_own_revenue_and_office(): void
    {
        [$receipt, $source, $client, $office, $data] = $this->example();
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->set('form', $data)->assertSee('Corretor (opcional)')->assertSee('Sem corretor')
            ->assertDontSee('Comissão do corretor (%)')->assertDontSee('Comissão do corretor (R$)')
            ->assertDontSee('Despesas a descontar do cliente')->assertDontSee('Adiantamentos a compensar na comissão')
            ->assertDontSee('Corretor líquido')->assertDontSee('Adiantamentos compensados')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(ProcessReceipt::sole()->broker_id);
        $this->assertSame('3600.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame(-24000.0, $client->fresh()->balance());
        $this->assertSame(-2400.0, $office->fresh()->balance());
        $this->assertSame(31000.0, $source->fresh()->balance());
        $this->assertSame(4600.0, BankAccount::all()->sum(fn ($account) => $account->balance()));
        $this->assertDatabaseCount('brokers', 0);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertDatabaseCount('case_types', 0);
        $this->assertDatabaseCount('receipt_destinations', 3);
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertSee('Sem corretor')->assertSee('Comissão do escritório')->assertDontSee('Comissões de corretor e escritório');
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->assertSet('form.broker_id', null);
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(3600, $report->viewData('ownIncome'));
        $this->assertEquals(3600, $report->viewData('byCategory')->get('Honorários próprios')['income']);
    }

    public function test_amount_mode_accepts_an_omitted_broker_and_omitted_broker_fields(): void
    {
        [$receipt, $source, $client, $office, $data] = $this->example();
        unset($data['broker_id'], $data['broker_amount'], $data['expenses'], $data['advances']);
        $data['fees_mode'] = 'amount';
        $data['fees'] = '6000';
        $data['commission_mode'] = 'amount';
        $data['office_amount'] = '1200';
        app(ProcessReceiptService::class)->save($receipt, $data);
        $this->assertNull(ProcessReceipt::sole()->broker_id);
        $this->assertSame('4800.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame(31000.0, $source->fresh()->balance());
        $this->assertSame(-24000.0, $client->fresh()->balance());
        $this->assertSame(-1200.0, $office->fresh()->balance());
        $this->assertDatabaseCount('broker_commissions', 0);
    }

    public function test_stale_broker_values_do_not_deduct_fees_or_consume_another_brokers_balances(): void
    {
        [$receipt, $source, , , $data] = $this->example();
        $broker = Broker::create(['name' => 'Outro corretor', 'status' => true]);
        $expense = app(BrokerProcessExpenseService::class)->save($broker, ['date' => now()->toDateString(), 'amount' => '500', 'description' => 'Passagem', 'bank_account_id' => $source->id]);
        $advance = BrokerAdvance::create(['broker_id' => $broker->id, 'date' => now()->toDateString(), 'amount' => 500]);
        $data['broker_amount'] = '99.99';
        $data['expenses'] = [$expense->id => '100'];
        $data['advances'] = [$advance->id => '100'];
        $service = app(ProcessReceiptService::class);
        $preview = $service->calculate($receipt, $data);
        $this->assertSame(0, $preview['broker']);
        $this->assertSame(360000, $preview['own']);
        $service->save($receipt, $data);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertDatabaseCount('broker_commission_settlements', 0);
        $this->assertDatabaseCount('process_expense_recoveries', 0);
        $this->assertSame(50000, $expense->fresh()->remainingCents());
        $this->assertSame(500.0, $advance->fresh()->remainingBalance());
        $this->assertSame('3600.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
    }

    public function test_clearing_the_broker_in_the_form_resets_its_commission_and_discounts(): void
    {
        [$receipt, , , , $data] = $this->example();
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $data['broker_id'] = $broker->id;
        $data['broker_amount'] = '25';
        $data['office_amount'] = '25';
        $component = Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->set('form', $data)->assertSee('Comissão do corretor (%)')
            ->set('form.expenses', [123 => '100'])->set('form.advances', [456 => '200'])
            ->set('form.broker_id', '')->assertSet('form.broker_amount', '0.00')
            ->assertSet('form.expenses', [])->assertSet('form.advances', [])
            ->assertDontSee('Comissão do corretor (%)')->assertDontSee('Corretor líquido');
        $this->assertSame(450000, $component->viewData('preview')['own']);
        $component->call('save')->assertHasNoErrors();
        $this->assertNull(ProcessReceipt::sole()->broker_id);
        $this->assertDatabaseCount('broker_commissions', 0);
    }

    public function test_switching_between_with_and_without_broker_preserves_totals_and_can_be_cleared_or_deleted(): void
    {
        [$receipt, $source, $client, $office, $data] = $this->example();
        $service = app(ProcessReceiptService::class);
        $service->save($receipt, $data);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $data['broker_id'] = $broker->id;
        $data['broker_amount'] = '20';
        $service->save($receipt, $data);
        $this->assertSame($broker->id, ProcessReceipt::sole()->broker_id);
        $this->assertSame('2400.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertDatabaseCount('broker_commissions', 1);
        $data['broker_id'] = null;
        $service->save($receipt, $data);
        $this->assertNull(ProcessReceipt::sole()->broker_id);
        $this->assertDatabaseCount('broker_commissions', 0);
        $this->assertSame('3600.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame(2, Transaction::where('type', 'transfer')->count());
        app(ReceiptDestinationService::class)->clear($receipt);
        $this->assertSame(31000.0, $source->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $service->save($receipt, $data);
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertSame(1000.0, $source->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0.0, $office->fresh()->balance());
        $this->assertDatabaseCount('process_receipts', 0);
        $this->assertDatabaseCount('receipt_destinations', 0);
    }

    public function test_optional_broker_still_rejects_an_invalid_selected_broker(): void
    {
        [$receipt, , , , $data] = $this->example();
        $data['broker_id'] = 99999;
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->set('form', $data)->call('save')->assertHasErrors('broker_id');
        $this->assertDatabaseCount('process_receipts', 0);
        $this->assertDatabaseCount('receipt_destinations', 0);
        $this->assertSame(1, Transaction::count());
    }

    public function test_percentage_rounding_without_broker_keeps_all_cents_in_own_and_office_shares(): void
    {
        [$receipt, , , $office, $data] = $this->example();
        $receipt->update(['amount' => '0.03']);
        $data['fees'] = '100';
        $data['office_amount'] = '50';
        $service = app(ProcessReceiptService::class);
        $values = $service->calculate($receipt, $data);
        $this->assertSame(0, $values['broker']);
        $this->assertSame(3, $values['own'] + $values['office']);
        $service->save($receipt, $data);
        $this->assertSame('0.01', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame(-0.02, $office->fresh()->balance());
        $this->assertDatabaseCount('broker_commissions', 0);
    }
}

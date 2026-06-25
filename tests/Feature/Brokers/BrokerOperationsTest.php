<?php

namespace Tests\Feature\Brokers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionRule;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BrokerOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Broker $broker;
    private BankAccount $account;
    private CaseType $caseType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broker = Broker::create(['name' => 'João Corretor']);
        $this->account = BankAccount::create(['name' => 'Conta Corrente', 'initial_balance' => 10000]);
        $this->caseType = CaseType::create(['name' => 'Previdenciário']);
    }

    public function test_advance_generates_banking_expense(): void
    {
        $advance = BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today(),
            'amount' => 500.00,
            'bank_account_id' => $this->account->id,
        ]);

        BrokerAdvancePaid::dispatch($advance);

        $transactions = Transaction::where('source_type', Broker::class)
            ->where('source_id', $this->broker->id)
            ->where('type', 'expense')
            ->get();

        $this->assertCount(1, $transactions);
        $this->assertEquals(500.00, (float) $transactions->first()->amount);
        $this->assertEquals($this->account->id, $transactions->first()->bank_account_id);
    }

    public function test_commission_calculation_uses_active_rule(): void
    {
        BrokerCommissionRule::create([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'percentage' => 10.000,
            'valid_from' => Carbon::today()->subMonths(2)->toDateString(),
            'valid_to' => Carbon::today()->subDays(1)->toDateString(),
        ]);

        BrokerCommissionRule::create([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'percentage' => 15.000,
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $service = app(BrokerCommissionService::class);
        $commission = $service->register([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => 1000.00,
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertEquals(15.000, (float) $commission->percentage_applied);
        $this->assertEquals(150.00, (float) $commission->commission_amount);
        $this->assertEquals('pending', $commission->status);
    }

    public function test_commission_calculation_fails_without_rule(): void
    {
        $service = app(BrokerCommissionService::class);

        $this->expectException(\DomainException::class);
        $service->register([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => 1000.00,
            'reference_date' => Carbon::today()->toDateString(),
        ]);
    }

    public function test_advance_settlement_reduces_balance(): void
    {
        $advance1 = BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->subDays(2)->toDateString(),
            'amount' => 100.00,
        ]);
        
        $advance2 = BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->subDays(1)->toDateString(),
            'amount' => 300.00,
        ]);

        BrokerCommissionRule::create([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'percentage' => 10.000,
            'valid_from' => Carbon::today()->subMonths(1)->toDateString(),
        ]);

        $service = app(BrokerCommissionService::class);
        $commission = $service->register([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => 2000.00, // 10% = 200.00 de comissão
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $settledAmount = $service->settleWithAdvances($commission);

        $this->assertEquals(200.00, $settledAmount);
        
        // Verifica status da comissão (foi 100% compensada, então está 'paid')
        $this->assertEquals('paid', $commission->fresh()->status);
        $this->assertEquals(0.00, $commission->fresh()->remainingAmount());

        // Verifica saldos dos adiantamentos
        $this->assertEquals(0.00, $advance1->fresh()->remainingBalance()); // Compensou os 100
        $this->assertEquals(200.00, $advance2->fresh()->remainingBalance()); // Compensou 100, sobraram 200
    }

    public function test_paid_commission_generates_banking_expense_for_net_amount(): void
    {
        $advance = BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 50.00,
        ]);

        BrokerCommissionRule::create([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'percentage' => 10.000,
            'valid_from' => Carbon::today()->subMonths(1)->toDateString(),
        ]);

        $service = app(BrokerCommissionService::class);
        $commission = $service->register([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => 1000.00, // 10% = 100.00
            'reference_date' => Carbon::today()->toDateString(),
            'bank_account_id' => $this->account->id,
        ]);

        // Compensa 50, sobra 50 a pagar
        $service->settleWithAdvances($commission);

        // Paga o restante
        $service->pay($commission->fresh());

        // Deve gerar uma transaction de 50 (o net amount)
        $transactions = Transaction::where('source_type', Broker::class)
            ->where('source_id', $this->broker->id)
            ->get();

        // Só terá 1 gerada aqui porque eu não disparei o evento de adiantamento, 
        // e o pagamento da comissão dispara o dela.
        $this->assertCount(1, $transactions);
        $this->assertEquals(50.00, (float) $transactions->first()->amount);
        $this->assertEquals($this->account->id, $transactions->first()->bank_account_id);
        $this->assertEquals('paid', $commission->fresh()->status);
    }

    public function test_partial_commission_payment_keeps_remaining_balance(): void
    {
        BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->subDay()->toDateString(),
            'amount' => 300.00,
        ]);

        BrokerCommissionRule::create([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'percentage' => 100.000,
            'valid_from' => Carbon::today()->subMonth()->toDateString(),
        ]);

        $service = app(BrokerCommissionService::class);
        $commission = $service->register([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'base_amount' => 1000.00,
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertEquals(300.00, $service->settleWithAdvances($commission));

        $payment = $service->payAmount(
            $commission->fresh(),
            350.00,
            Carbon::today()->toDateString(),
            $this->account->id,
            'Repasse parcial',
        );

        $this->assertInstanceOf(BrokerCommissionPayment::class, $payment);
        $this->assertEquals(350.00, (float) $payment->amount);
        $this->assertEquals(350.00, $commission->fresh()->remainingAmount());
        $this->assertEquals('partially_paid', $commission->fresh()->status);

        $this->assertDatabaseHas('transactions', [
            'source_type' => Broker::class,
            'source_id' => $this->broker->id,
            'type' => 'expense',
            'amount' => 350.00,
        ]);
    }

    public function test_fixed_commission_offsets_existing_advance(): void
    {
        BrokerAdvance::create([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->subDay()->toDateString(),
            'amount' => 300.00,
        ]);

        $service = app(BrokerCommissionService::class);
        $commission = $service->registerFixedAmount([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'commission_amount' => 1000.00,
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $settled = $service->settleWithAdvances($commission);

        $this->assertEquals(300.00, $settled);
        $this->assertEquals(700.00, $commission->fresh()->remainingAmount());
        $this->assertEquals('partially_paid', $commission->fresh()->status);
    }

    public function test_advance_splits_payment_between_repasse_and_remaining_advance(): void
    {
        $service = app(BrokerCommissionService::class);
        $commission = $service->registerFixedAmount([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'commission_amount' => 200.00,
            'reference_date' => Carbon::today()->toDateString(),
            'bank_account_id' => $this->account->id,
        ]);

        $result = app(BrokerAdvanceService::class)->register([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 300.00,
            'bank_account_id' => $this->account->id,
            'payment_method' => 'PIX',
        ]);

        $this->assertEquals(200.00, $result['repassed_amount']);
        $this->assertEquals(100.00, $result['advance_amount']);
        $this->assertNotNull($result['advance']);
        $this->assertEquals(100.00, (float) $result['advance']->amount);
        $this->assertEquals('paid', $commission->fresh()->status);
        $this->assertEquals(0.00, $commission->fresh()->remainingAmount());

        $this->assertDatabaseHas('broker_commission_payments', [
            'broker_id' => $this->broker->id,
            'commission_id' => $commission->id,
            'amount' => 200.00,
        ]);

        $this->assertDatabaseHas('broker_advances', [
            'broker_id' => $this->broker->id,
            'amount' => 100.00,
        ]);

        $transactions = Transaction::where('source_type', Broker::class)
            ->where('source_id', $this->broker->id)
            ->orderBy('amount')
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertEquals([100.00, 200.00], $transactions->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
    }

    public function test_advance_without_commission_balance_creates_only_advance(): void
    {
        $result = app(BrokerAdvanceService::class)->register([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 250.50,
            'bank_account_id' => $this->account->id,
        ]);

        $this->assertEquals(0.00, $result['repassed_amount']);
        $this->assertEquals(250.50, $result['advance_amount']);
        $this->assertNull($result['payments']->first());
        $this->assertDatabaseCount('broker_commission_payments', 0);
        $this->assertDatabaseHas('broker_advances', [
            'broker_id' => $this->broker->id,
            'amount' => 250.50,
        ]);
    }

    public function test_advance_smaller_than_commission_balance_registers_only_repasse(): void
    {
        $service = app(BrokerCommissionService::class);
        $commission = $service->registerFixedAmount([
            'broker_id' => $this->broker->id,
            'case_type_id' => $this->caseType->id,
            'commission_amount' => 200.00,
            'reference_date' => Carbon::today()->toDateString(),
            'bank_account_id' => $this->account->id,
        ]);

        $result = app(BrokerAdvanceService::class)->register([
            'broker_id' => $this->broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 150.00,
            'bank_account_id' => $this->account->id,
        ]);

        $this->assertEquals(150.00, $result['repassed_amount']);
        $this->assertEquals(0.00, $result['advance_amount']);
        $this->assertNull($result['advance']);
        $this->assertDatabaseCount('broker_advances', 0);
        $this->assertEquals(50.00, $commission->fresh()->remainingAmount());
        $this->assertEquals('partially_paid', $commission->fresh()->status);
    }

    public function test_brokers_index_modal_creates_broker_advance_for_contact(): void
    {
        $contact = Contact::create([
            'name' => 'Maria Corretora',
            'type' => 'corretor',
            'document' => '12345678900',
            'status' => true,
        ]);

        Volt::test('brokers.index')
            ->call('openLaunchModal')
            ->set('launch_contact_id', $contact->id)
            ->set('launch_type', 'advance')
            ->set('launch_date', Carbon::today()->toDateString())
            ->set('launch_amount', '250.50')
            ->set('launch_bank_account_id', $this->account->id)
            ->call('saveLaunch')
            ->assertHasNoErrors()
            ->assertSet('showLaunchModal', false);

        $financialBroker = Broker::where('contact_id', $contact->id)->firstOrFail();

        $this->assertDatabaseHas('broker_advances', [
            'broker_id' => $financialBroker->id,
            'amount' => 250.50,
        ]);

        $this->assertDatabaseHas('transactions', [
            'type' => 'expense',
            'source_type' => Broker::class,
            'source_id' => $financialBroker->id,
            'bank_account_id' => $this->account->id,
            'amount' => 250.50,
        ]);
    }
}

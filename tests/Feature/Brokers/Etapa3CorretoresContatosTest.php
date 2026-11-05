<?php

namespace Tests\Feature\Brokers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Models\WritDefaultBroker;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use App\Domains\Brokers\Services\BrokerReportService;
use App\Domains\Brokers\Services\BrokerStatementService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use App\Support\BrazilianDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa3CorretoresContatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_accepts_multiple_roles_and_keeps_type_compatible(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('contacts.form')
            ->set('name', 'Maria Multi')
            ->set('roles', ['cedente', 'corretor'])
            ->set('document', '390.533.447-05')
            ->set('pix_key', 'maria@example.com')
            ->set('pix_key_type', 'email')
            ->call('save')
            ->assertHasNoErrors();

        $contact = Contact::where('name', 'Maria Multi')->firstOrFail();
        $this->assertTrue($contact->hasRole('cedente'));
        $this->assertTrue($contact->hasRole('corretor'));
        $this->assertContains($contact->type, ['cedente', 'corretor']);
        $this->assertNotNull($contact->brokerProfile);
        $this->assertEquals(1, Contact::ofType('corretor')->whereKey($contact->id)->count());
        $this->assertEquals(1, Contact::ofType('cedente')->whereKey($contact->id)->count());
    }

    public function test_cedente_requires_valid_cpf_and_phone_validation(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('contacts.form')
            ->set('name', 'Sem CPF')
            ->set('roles', ['cedente'])
            ->set('document', '')
            ->call('save')
            ->assertHasErrors(['document']);

        Volt::test('contacts.form')
            ->set('name', 'CPF inválido')
            ->set('roles', ['cedente'])
            ->set('document', '111.111.111-11')
            ->call('save')
            ->assertHasErrors(['document']);

        Volt::test('contacts.form')
            ->set('name', 'Telefone inválido')
            ->set('roles', ['pessoal'])
            ->set('phones', ['123'])
            ->call('save')
            ->assertHasErrors(['phones.0']);

        $this->assertTrue(BrazilianDocuments::isValidCpf('390.533.447-05'));
        $this->assertTrue(BrazilianDocuments::isValidPhone('(98) 98888-8888'));
    }

    public function test_existing_invalid_document_is_not_deleted_and_shows_incomplete(): void
    {
        $contact = Contact::create([
            'name' => 'Legado Inválido',
            'type' => 'cedente',
            'roles' => ['cedente'],
            'document' => '123',
            'status' => true,
        ]);

        $this->assertTrue($contact->fresh()->isIncomplete());
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'document' => '123']);
    }

    public function test_commission_percent_and_fixed_with_process_link(): void
    {
        $broker = Broker::create(['name' => 'Corretor Comissão']);
        $caseType = CaseType::create(['name' => 'Previdenciário']);
        $service = app(BrokerCommissionService::class);

        $percent = $service->registerFlexible([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'name' => 'Processo A',
            'mode' => 'percent',
            'rate_or_amount' => 10,
            'base_amount' => 1000,
            'process_ref' => '0001111-22.2024.8.10.0001',
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertEquals('percent', $percent->commission_mode);
        $this->assertEquals(100.0, (float) $percent->commission_amount);
        $this->assertEquals('0001111-22.2024.8.10.0001', $percent->process_ref);
        $this->assertStringContainsString('10', $percent->calculationLabel());

        $fixed = $service->registerFlexible([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'name' => 'Processo B',
            'mode' => 'fixed',
            'rate_or_amount' => 250,
            'process_ref' => 'PROC-B',
            'reference_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertEquals('fixed', $fixed->commission_mode);
        $this->assertEquals(250.0, (float) $fixed->commission_amount);
    }

    public function test_personal_advance_and_process_expense_balances_are_separated(): void
    {
        $broker = Broker::create(['name' => 'Corretor Saldos']);
        $account = BankAccount::create(['name' => 'Conta Teste', 'initial_balance' => 50000]);
        $caseType = CaseType::create(['name' => 'Geral']);
        $advances = app(BrokerAdvanceService::class);
        $commissions = app(BrokerCommissionService::class);
        $expenses = app(BrokerProcessExpenseService::class);

        $advances->register([
            'broker_id' => $broker->id,
            'date' => Carbon::today()->subDay()->toDateString(),
            'amount' => 1000,
            'bank_account_id' => $account->id,
        ]);

        $commission = $commissions->registerFlexible([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'name' => 'Caso',
            'mode' => 'fixed',
            'rate_or_amount' => 200,
            'reference_date' => Carbon::today()->toDateString(),
        ]);
        $commissions->settleWithAdvances($commission);

        $expenses->save($broker, [
            'date' => Carbon::today()->toDateString(),
            'amount' => '300.00',
            'description' => 'Custas do cliente',
            'bank_account_id' => $account->id,
            'process_ref' => 'PROC-1',
        ]);

        $ledger = app(BrokerBalanceCalculator::class)->ledgerForBroker($broker->fresh());

        $this->assertEquals(800.0, $ledger['saldo_adiantado_pessoal']);
        $this->assertEquals(0.0, $ledger['comissao_a_pagar']);
        $this->assertEquals(800.0, $ledger['saldo_devedor']);
        $this->assertEquals(300.0, $ledger['despesa_processo_em_aberto']);

        $summary = app(BrokerStatementService::class)->summary($broker->fresh());
        $this->assertEquals(800.0, $summary['advance_pending']);
        $this->assertEquals(300.0, $summary['process_expenses_open']);
    }

    public function test_statement_has_running_balance_line_by_line(): void
    {
        $broker = Broker::create(['name' => 'Corretor Extrato']);
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 10000]);
        $caseType = CaseType::create(['name' => 'Tipo']);

        app(BrokerAdvanceService::class)->register([
            'broker_id' => $broker->id,
            'date' => '2026-01-10',
            'amount' => 1000,
            'bank_account_id' => $account->id,
        ]);
        $commission = app(BrokerCommissionService::class)->registerFlexible([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'name' => 'X',
            'mode' => 'fixed',
            'rate_or_amount' => 200,
            'reference_date' => '2026-01-15',
        ]);
        app(BrokerCommissionService::class)->settleWithAdvances($commission);

        $entries = app(BrokerStatementService::class)->entries($broker->fresh(), ascending: true);
        $personal = $entries->where('affects_personal', true)->values();
        $this->assertGreaterThanOrEqual(2, $personal->count());
        $this->assertEquals(1000.0, (float) $personal->first()['running_balance']);
        $this->assertEquals(800.0, (float) $personal->last()['running_balance']);
    }

    public function test_report_defaults_to_current_year_and_excludes_test_contacts(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 12, 0, 0, 'America/Sao_Paulo'));

        $brokerOk = Broker::create(['name' => 'Corretor Real']);
        $caseType = CaseType::create(['name' => 'Tipo']);
        BrokerCommission::create([
            'broker_id' => $brokerOk->id,
            'case_type_id' => $caseType->id,
            'name' => 'Real',
            'base_amount' => 100,
            'percentage_applied' => 0,
            'commission_mode' => 'fixed',
            'rate_or_amount' => 100,
            'commission_amount' => 100,
            'status' => 'pending',
            'reference_date' => '2026-03-01',
        ]);

        $testContact = Contact::create([
            'name' => 'TESTE CORRETOR',
            'type' => 'corretor',
            'roles' => ['corretor'],
            'is_test' => true,
            'status' => true,
        ]);
        $brokerTest = Broker::create(['name' => 'TESTE CORRETOR', 'contact_id' => $testContact->id]);
        BrokerCommission::create([
            'broker_id' => $brokerTest->id,
            'case_type_id' => $caseType->id,
            'name' => 'Teste',
            'base_amount' => 999,
            'percentage_applied' => 0,
            'commission_mode' => 'fixed',
            'rate_or_amount' => 999,
            'commission_amount' => 999,
            'status' => 'pending',
            'reference_date' => '2026-03-01',
        ]);

        $this->actingAs(User::factory()->create());
        Volt::test('brokers.reports')
            ->assertSet('year', '2026');

        $report = app(BrokerReportService::class)->generate('all', 2026);
        $this->assertEquals(100.0, (float) $report['totalCommissions']);
        $this->assertTrue(collect($report['annualByBroker'])->contains(fn ($row) => $row['broker_name'] === 'Corretor Real' && $row['total'] == 100.0));
        $this->assertFalse(collect($report['annualByBroker'])->contains(fn ($row) => $row['broker_name'] === 'TESTE CORRETOR'));

        Carbon::setTestNow();
    }

    public function test_reclassify_personal_advance_to_process_expense(): void
    {
        $broker = Broker::create(['name' => 'Corretor Reclassifica']);
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 10000]);
        $result = app(BrokerAdvanceService::class)->register([
            'broker_id' => $broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 150,
            'bank_account_id' => $account->id,
            'notes' => 'Era pessoal',
        ]);
        $advance = $result['advance'];

        $expense = app(BrokerAdvanceService::class)->reclassifyAsProcessExpense($advance, 'Custas reclassificadas', 'PROC-9');

        $this->assertInstanceOf(BrokerProcessExpense::class, $expense);
        $this->assertEquals(150.0, (float) $expense->amount);
        $this->assertEquals('PROC-9', $expense->process_ref);
        $this->assertDatabaseMissing('broker_advances', ['id' => $advance->id]);
        $ledger = app(BrokerBalanceCalculator::class)->ledgerForBroker($broker->fresh());
        $this->assertEquals(0.0, $ledger['saldo_adiantado_pessoal']);
        $this->assertEquals(150.0, $ledger['despesa_processo_em_aberto']);
    }

    public function test_writ_default_brokers_prefill_receipt_list(): void
    {
        $broker = Broker::create(['name' => 'Corretor Prefill', 'status' => true]);
        $contact = Contact::create(['name' => 'Corretor Prefill', 'type' => 'corretor', 'roles' => ['corretor'], 'status' => true]);
        $broker->update(['contact_id' => $contact->id]);

        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'paid',
            'process_number' => '123',
            'face_value' => 1000,
            'negotiated_amount' => 800,
            'proposed_amount' => 800,
            'paid_amount' => 700,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'estimated_receipt_amount' => 900,
            'assignor_name' => 'Fulano',
        ]);
        WritDefaultBroker::create([
            'writ_id' => $writ->id,
            'broker_id' => $broker->id,
            'base' => 'fees',
            'mode' => 'percent',
            'rate_or_amount' => 10,
        ]);

        $this->assertCount(1, $writ->fresh()->defaultBrokers);
        $this->assertEquals(10.0, (float) $writ->defaultBrokers->first()->rate_or_amount);
    }

    public function test_old_advances_default_to_personal_kind(): void
    {
        $broker = Broker::create(['name' => 'Legado']);
        $advance = BrokerAdvance::create([
            'broker_id' => $broker->id,
            'date' => Carbon::today()->toDateString(),
            'amount' => 50,
        ]);
        $this->assertEquals('personal', $advance->fresh()->kind);
        $this->assertTrue($advance->fresh()->isPersonal());
    }
}

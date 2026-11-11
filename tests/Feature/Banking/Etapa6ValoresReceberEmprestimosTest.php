<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Banking\Services\ReceivableService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use App\Support\SimpleInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa6ValoresReceberEmprestimosTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    private BankAccount $cash;

    private Contact $person;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 20000, 'status' => true]);
        $this->cash = BankAccount::create(['name' => 'Dinheiro', 'type' => BankAccount::TYPE_CASH, 'initial_balance' => 0, 'status' => true]);
        $this->person = Contact::create(['name' => 'Ana', 'type' => 'pessoal', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function totals(): array
    {
        return app(OwnFinancialService::class)->totals(today()->startOfMonth(), today()->endOfMonth());
    }

    public function test_simple_interest_formulas_match_agreed_examples(): void
    {
        $this->assertSame(6000, SimpleInterest::interestCents(100000, 2, 90)); // R$ 60,00
        $this->assertSame(2000, SimpleInterest::penaltyCents(100000, 2));
        $this->assertSame(1000, SimpleInterest::interestCents(100000, 1, 30));
        $balance = SimpleInterest::balance(100000, '2026-01-01', '2026-01-01', 0, 2, 1, '2026-01-31');
        $this->assertSame(3000, $balance['penalty_open'] + $balance['interest_open']); // 20 + 10
        $alloc = SimpleInterest::allocate(2500, 100000, 1000, 2000);
        $this->assertSame(['principal' => 0, 'interest' => 500, 'penalty' => 2000], $alloc);
    }

    public function test_receivable_without_charges_still_excludes_principal_from_expense_and_income(): void
    {
        $service = app(ReceivableService::class);
        $row = $service->create([
            'origin' => 'bank', 'date' => today()->toDateString(), 'description' => 'Jantar', 'amount' => '1000.00',
            'bank_account_id' => $this->bank->id, 'installments' => 1, 'repayment_mode' => 'undated',
            'request_key' => (string) Str::uuid(), 'people' => [['contact_id' => $this->person->id, 'amount' => '1000.00']],
        ])[0];
        $this->assertEquals(0, $this->totals()['expense']);
        $this->assertSame(0.0, (float) $row->interest_rate_am);
        $service->receive($row, '400.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $this->assertEquals(0, $this->totals()['income']);
        $this->assertSame(60000, $row->fresh()->balanceCents());
    }

    public function test_receivable_interest_and_cash_repayment_allocation(): void
    {
        $service = app(ReceivableService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $row = $service->create([
            'origin' => 'bank', 'date' => '2026-01-01', 'description' => 'Adiantamento', 'amount' => '1000.00',
            'bank_account_id' => $this->bank->id, 'installments' => 1, 'repayment_mode' => 'dated',
            'first_due_date' => '2026-02-01', 'interest_rate_am' => 2, 'penalty_pct' => 2, 'late_interest_rate_am' => 1,
            'request_key' => (string) Str::uuid(), 'people' => [['contact_id' => $this->person->id, 'amount' => '1000.00']],
        ])[0];

        // Sem vencimento ainda: 90 dias de juros combinados = R$ 60.
        Carbon::setTestNow('2026-04-01 12:00:00');
        $beforeDue = $row->fresh(['installments.settlements', 'settlements'])->balanceAsOf('2026-01-31');
        // Força apuração só de juros combinados recriando sem mora no meio do caminho:
        $onlyInterest = \App\Support\SimpleInterest::balance(100000, '2026-01-01', null, 2, 0, 0, '2026-04-01');
        $this->assertSame(6000, $onlyInterest['interest_open']);

        $balance = $row->fresh(['installments.settlements', 'settlements'])->balanceAsOf('2026-04-01');
        $this->assertSame(2000, $balance['penalty_open']); // multa 2% após 01/02
        $this->assertTrue($balance['interest_open'] >= 6000); // combinados + mora
        $this->assertTrue($balance['days_late'] >= 30);

        $beforeCash = $this->cash->fresh()->balance();
        $service->receive($row, '20.00', '2026-04-01', $this->cash->id, (string) Str::uuid());
        $row = $row->fresh(['installments.settlements', 'settlements']);
        $settlement = $row->settlements->first();
        $this->assertEquals(20, (float) $settlement->amount);
        $this->assertEquals(0, (float) $settlement->principal_amount);
        $this->assertEquals(20, (float) $settlement->penalty_amount); // multa primeiro
        $this->assertEquals($beforeCash + 20, $this->cash->fresh()->balance());
        $this->assertEquals(20, $this->totals()['income']);
        $this->assertEquals(0, $this->totals()['expense']);
    }

    public function test_loan_disbursement_is_not_expense_and_partial_repayments_reduce_principal(): void
    {
        $service = app(LoanService::class);
        $loan = $service->create([
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Empréstimo Ana',
            'principal' => '5000.00',
            'date' => today()->toDateString(),
            'installments' => 5,
            'first_due_date' => today()->addMonthNoOverflow()->toDateString(),
            'interest_rate_am' => 0,
            'request_key' => (string) Str::uuid(),
        ]);
        $this->assertEquals(15000, $this->bank->fresh()->balance());
        $this->assertEquals(0, $this->totals()['expense']);
        $this->assertSame(Category::loanDisbursementCategoryId(), (int) $loan->disbursement->category_id);
        $this->assertCount(5, $loan->installments()->get());

        $service->receive($loan, '1000.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $service->receive($loan, '1000.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $loan = $loan->fresh(['installments.settlements', 'settlements']);
        $this->assertSame(300000, $loan->remainingCents());
        $this->assertEquals(0, $this->totals()['income']); // só principal
        $this->assertSame('Parcialmente recebido', $loan->statusLabel());
    }

    public function test_loan_interest_received_is_income_principal_is_not(): void
    {
        $service = app(LoanService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $loan = $service->create([
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Com juros',
            'principal' => '1000.00',
            'date' => '2026-01-01',
            'installments' => 1,
            'first_due_date' => '2026-04-01',
            'interest_rate_am' => 2,
            'request_key' => (string) Str::uuid(),
        ]);
        Carbon::setTestNow('2026-04-01 12:00:00');
        $balance = $loan->fresh(['settlements', 'installments.settlements'])->balanceAsOf('2026-04-01');
        $this->assertSame(6000, $balance['interest_open']);
        $service->receive($loan, '1060.00', '2026-04-01', $this->bank->id, (string) Str::uuid());
        $loan = $loan->fresh(['settlements', 'installments.settlements']);
        $this->assertSame(0, $loan->remainingCents());
        $this->assertSame('Quitado', $loan->statusLabel());
        $this->assertEquals(60, $this->totals()['income']);
        $this->assertEquals(0, $this->totals()['expense']);
        $income = Transaction::where('type', 'income')->latest('id')->first();
        $this->assertSame(Category::loanInterestCategoryId(), (int) $income->category_id);
    }

    public function test_loan_routes_menu_and_paguei_por_alguem_label_require_auth(): void
    {
        $this->get(route('banking.loans.index'))->assertOk()->assertSee('Empréstimos concedidos')->assertSee('Empréstimos');
        Volt::test('banking.loans.index')->assertSee('Novo empréstimo');
        Volt::test('banking.receivables.index')
            ->assertSee('Registrar que paguei por alguém')
            ->call('startCreate')
            ->assertSee('Paguei por alguém')
            ->assertSee('Encargos opcionais');
        $this->get(route('banking.transactions.index'))->assertOk();

        auth()->logout();
        $this->get(route('banking.loans.index'))->assertRedirect();
    }

    public function test_loan_attachment_is_private_and_downloadable_when_authenticated(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('recibo.pdf', 100, 'application/pdf');
        $loan = app(LoanService::class)->create([
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Com anexo',
            'principal' => '100.00',
            'date' => today()->toDateString(),
            'installments' => 1,
            'interest_rate_am' => 0,
            'request_key' => (string) Str::uuid(),
        ], $file);
        $this->assertTrue($loan->fresh()->hasAttachment());
        $this->get(route('banking.loans.attachment', $loan))->assertOk();
        auth()->logout();
        $this->get(route('banking.loans.attachment', $loan))->assertRedirect();
    }

    public function test_receivable_page_exposes_cash_option_and_charges_fields(): void
    {
        $component = Volt::test('banking.receivables.index')->call('startCreate');
        $this->assertEquals(0, (float) $component->get('form.interest_rate_am'));
        $component->assertSee('Juros combinados')->assertSee('Encargos opcionais');
    }
}

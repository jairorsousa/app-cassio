<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\ReceivableService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use App\Support\SimpleInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InterestTypeTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    private Contact $person;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 20000, 'status' => true]);
        $this->person = Contact::create(['name' => 'Ana', 'type' => 'pessoal', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_loan_defaults_to_simple_interest_and_keeps_previous_total(): void
    {
        $service = app(LoanService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $loan = $service->create([
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Sem tipo informado',
            'principal' => '1000.00',
            'date' => '2026-01-01',
            'installments' => 1,
            'first_due_date' => '2026-04-01',
            'interest_rate_am' => 2,
            'request_key' => (string) Str::uuid(),
        ]);
        $this->assertSame(SimpleInterest::TYPE_SIMPLE, $loan->interest_type);
        $this->assertSame('Simples', SimpleInterest::typeLabel($loan->interestType()));
        Carbon::setTestNow('2026-04-01 12:00:00');
        $balance = $loan->fresh(['settlements', 'installments'])->balanceAsOf('2026-04-01');
        $this->assertSame(6000, $balance['interest_open']);
        $this->assertSame(106000, $balance['balance']);
    }

    public function test_loan_compound_interest_is_saved_and_can_be_switched_back_on_edit(): void
    {
        $service = app(LoanService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $loan = $service->create([
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Compostos',
            'principal' => '1000.00',
            'date' => '2026-01-01',
            'installments' => 1,
            'first_due_date' => '2026-04-01',
            'interest_rate_am' => 2,
            'interest_type' => 'compound',
            'request_key' => (string) Str::uuid(),
        ]);
        $this->assertSame(SimpleInterest::TYPE_COMPOUND, $loan->interest_type);
        Carbon::setTestNow('2026-04-01 12:00:00');
        $this->assertSame(6121, $loan->fresh(['settlements', 'installments'])->balanceAsOf('2026-04-01')['interest_open']);

        $updated = $service->update($loan, [
            'contact_id' => $this->person->id,
            'bank_account_id' => $this->bank->id,
            'description' => 'Compostos',
            'principal' => '1000.00',
            'date' => '2026-01-01',
            'installments' => 1,
            'first_due_date' => '2026-04-01',
            'interest_rate_am' => 2,
            'interest_type' => 'simple',
        ]);
        $this->assertSame(SimpleInterest::TYPE_SIMPLE, $updated->interest_type);
        $this->assertSame(6000, $updated->fresh(['settlements', 'installments'])->balanceAsOf('2026-04-01')['interest_open']);
    }

    public function test_receivable_defaults_to_simple_and_edit_can_switch_to_compound(): void
    {
        $service = app(ReceivableService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $row = $service->create([
            'origin' => 'bank', 'date' => '2026-01-01', 'description' => 'Adiantamento', 'amount' => '1000.00',
            'bank_account_id' => $this->bank->id, 'installments' => 1, 'repayment_mode' => 'dated',
            'first_due_date' => '2026-04-01', 'interest_rate_am' => 2,
            'request_key' => (string) Str::uuid(), 'people' => [['contact_id' => $this->person->id, 'amount' => '1000.00']],
        ])[0];
        $this->assertSame(SimpleInterest::TYPE_SIMPLE, $row->interest_type);
        $this->assertSame(SimpleInterest::TYPE_SIMPLE, $row->late_interest_type);
        $this->assertSame(6000, $row->fresh(['installments.settlements', 'settlements'])->balanceAsOf('2026-04-01')['interest_open']);

        $updated = $service->updateCharges($row, [
            'interest_rate_am' => 2,
            'interest_type' => 'compound',
            'penalty_pct' => 0,
            'late_interest_rate_am' => 0,
            'late_interest_type' => 'simple',
            'interest_start_date' => '2026-01-01',
        ]);
        $this->assertSame(SimpleInterest::TYPE_COMPOUND, $updated->interest_type);
        $this->assertSame(6121, $updated->fresh(['installments.settlements', 'settlements'])->balanceAsOf('2026-04-01')['interest_open']);
    }

    public function test_receivable_late_interest_type_is_independent(): void
    {
        $service = app(ReceivableService::class);
        Carbon::setTestNow('2026-01-01 12:00:00');
        $row = $service->create([
            'origin' => 'bank', 'date' => '2026-01-01', 'description' => 'Com mora', 'amount' => '1000.00',
            'bank_account_id' => $this->bank->id, 'installments' => 1, 'repayment_mode' => 'dated',
            'first_due_date' => '2026-01-01', 'interest_rate_am' => 0, 'late_interest_rate_am' => 1,
            'interest_type' => 'simple', 'late_interest_type' => 'compound',
            'request_key' => (string) Str::uuid(), 'people' => [['contact_id' => $this->person->id, 'amount' => '1000.00']],
        ])[0];
        $balance = $row->fresh(['installments.settlements', 'settlements'])->balanceAsOf('2026-04-01');
        $this->assertSame((int) round(100_000 * (pow(1.01, 3) - 1)), $balance['interest_open']);
    }

    public function test_loan_and_receivable_forms_show_type_selector_defaulting_to_simple(): void
    {
        $loan = Volt::test('banking.loans.index')->call('startCreate');
        $this->assertSame('simple', $loan->get('form.interest_type'));
        $loan->assertSee('Simples')->assertSee('Compostos')->assertSee('Taxa de juros (% a.m.)');

        $receivable = Volt::test('banking.receivables.index')->call('startCreate');
        $this->assertSame('simple', $receivable->get('form.interest_type'));
        $this->assertSame('simple', $receivable->get('form.late_interest_type'));
        $receivable->assertSee('Simples')->assertSee('Compostos')->assertSee('Tipo da mora');
    }

    public function test_loan_form_preview_compares_simple_and_compound_totals(): void
    {
        $component = Volt::test('banking.loans.index')->call('startCreate')
            ->set('form.principal', '1000.00')
            ->set('form.date', '2026-01-01')
            ->set('form.first_due_date', '2026-04-01')
            ->set('form.interest_rate_am', '2');
        $component->assertSee('Prévia até 01/04/2026')
            ->assertSee('Simples: R$ 1.060,00')
            ->assertSee('Compostos: R$ 1.061,21');
    }
}

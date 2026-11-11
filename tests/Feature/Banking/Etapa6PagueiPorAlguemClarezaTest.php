<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceivableService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa6PagueiPorAlguemClarezaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_service_accepts_brazilian_money_mask_and_empty_interest_start_date(): void
    {
        $account = BankAccount::create(['name' => 'BB Fortaleza', 'initial_balance' => 20000, 'status' => true]);
        $person = Contact::create(['name' => 'Heleno Mota', 'type' => 'pessoal', 'status' => true]);

        $rows = app(ReceivableService::class)->create([
            'origin' => 'bank',
            'date' => '2026-10-30',
            'description' => 'Multa S10, Heleno',
            'amount' => '1.467,35',
            'bank_account_id' => $account->id,
            'installments' => 1,
            'repayment_mode' => 'dated',
            'first_due_date' => '2026-11-30',
            'interest_rate_am' => '0',
            'penalty_pct' => '0',
            'late_interest_rate_am' => '0',
            'interest_start_date' => '',
            'people' => [['contact_id' => $person->id, 'amount' => '1.467,35']],
            'request_key' => (string) Str::uuid(),
        ]);

        $row = $rows[0]->fresh();
        $this->assertSame('1467.35', number_format((float) $row->amountCents() / 100, 2, '.', ''));
        $this->assertSame('2026-10-30', $row->interest_start_date?->toDateString());
        $this->assertSame(1, Transaction::count());
        $this->assertSame('1467.35', number_format((float) Transaction::first()->amount, 2, '.', ''));
        $this->assertSame('Multa S10, Heleno', Transaction::first()->description);
    }

    public function test_form_saves_brazilian_mask_and_empty_interest_date_without_leaving_partial_data_on_error(): void
    {
        $account = BankAccount::create(['name' => 'BB Fortaleza', 'initial_balance' => 20000, 'status' => true]);
        $person = Contact::create(['name' => 'Heleno Mota', 'type' => 'pessoal', 'status' => true]);

        Volt::test('banking.receivables.index')
            ->call('startCreate')
            ->set('form.origin', 'bank')
            ->set('form.date', '2026-10-30')
            ->set('form.description', 'Multa S10, Heleno')
            ->set('form.amount', '1.467,35')
            ->set('form.bank_account_id', $account->id)
            ->set('form.interest_start_date', '')
            ->set('form.people.0.contact_id', $person->id)
            ->set('form.people.0.amount', '1.467,35')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Receivable::count());
        $this->assertSame('2026-10-30', Receivable::first()->interest_start_date?->toDateString());
        $this->assertSame(1, Transaction::count());
    }

    public function test_form_keeps_errors_inside_the_modal_when_person_is_missing(): void
    {
        $account = BankAccount::create(['name' => 'BB Fortaleza', 'initial_balance' => 20000, 'status' => true]);

        Volt::test('banking.receivables.index')
            ->call('startCreate')
            ->set('form.origin', 'bank')
            ->set('form.date', '2026-10-30')
            ->set('form.description', 'Jantar')
            ->set('form.amount', '150,00')
            ->set('form.bank_account_id', $account->id)
            ->set('form.people.0.contact_id', null)
            ->set('form.people.0.amount', '150,00')
            ->call('save')
            ->assertHasErrors(['form.people.0.contact_id'])
            ->assertSee('Selecione a pessoa que deve devolver')
            ->assertSee('Paguei por alguém')
            ->assertSee('Antes de salvar, confira o que vai acontecer');

        $this->assertSame(0, Receivable::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_form_explains_money_flow_and_shows_preview(): void
    {
        $account = BankAccount::create(['name' => 'Conta Poupança', 'initial_balance' => 5000, 'status' => true]);
        $person = Contact::create(['name' => 'Ana Silva', 'type' => 'pessoal', 'status' => true]);

        Volt::test('banking.receivables.index')
            ->call('startCreate')
            ->set('form.origin', 'bank')
            ->set('form.bank_account_id', $account->id)
            ->set('form.amount', '200,00')
            ->set('form.people.0.contact_id', $person->id)
            ->set('form.people.0.amount', '80,00')
            ->assertSee('Você pagou por outra pessoa: o valor sai da sua conta')
            ->assertSee('Antes de salvar, confira o que vai acontecer')
            ->assertSee('O valor de R$ 200,00 sai da conta Conta Poupança.')
            ->assertSee('R$ 80,00 vira valor a receber de Ana Silva.')
            ->assertSee('R$ 120,00 fica como sua despesa própria.');
    }

    public function test_expense_shortcut_accepts_brazilian_money_mask(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);

        Volt::test('banking.transactions.index')
            ->call('create', 'expense')
            ->set('formDate', '2026-11-05')
            ->set('formAmount', '1.467,35')
            ->set('formDescription', 'Multa S10, Heleno')
            ->set('formBankAccountId', $account->id)
            ->call('payForSomeone')
            ->assertHasNoErrors()
            ->assertRedirect(route('banking.receivables.index', [
                'criar' => '1',
                'origin' => 'bank',
                'date' => '2026-11-05',
                'amount' => '1467.35',
                'description' => 'Multa S10, Heleno',
                'installments' => 1,
                'bank_account_id' => $account->id,
            ]));

        $this->assertSame(0, Transaction::count());
    }

    public function test_draft_page_uses_clear_title_and_explanation(): void
    {
        $this->get(route('banking.receivables.index', [
            'criar' => '1',
            'origin' => 'bank',
            'date' => '2026-11-05',
            'amount' => '150.50',
            'description' => 'Jantar com Ana',
        ]))->assertOk()
            ->assertSee('Paguei por alguém')
            ->assertSee('Jantar com Ana')
            ->assertSee('Você pagou por outra pessoa: o valor sai da sua conta')
            ->assertSee('Antes de salvar, confira o que vai acontecer');
    }
}

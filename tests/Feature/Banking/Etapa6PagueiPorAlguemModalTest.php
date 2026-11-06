<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa6PagueiPorAlguemModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expense_and_card_modals_show_paguei_por_alguem_but_income_and_transfer_do_not(): void
    {
        Volt::test('banking.transactions.index')->call('create', 'expense')
            ->assertSee('Nova despesa')
            ->assertSee('Paguei por alguém')
            ->assertSee('Abre Valores a receber');

        Volt::test('banking.transactions.index')->call('create', 'card_expense')
            ->assertSee('Nova despesa no cartão')
            ->assertSee('Paguei por alguém');

        Volt::test('banking.transactions.index')->call('create', 'income')
            ->assertSee('Nova receita')
            ->assertDontSee('Paguei por alguém');

        Volt::test('banking.transactions.index')->call('create', 'transfer')
            ->assertSee('Nova transferência')
            ->assertDontSee('Paguei por alguém');
    }

    public function test_new_expense_shortcut_opens_receivables_draft_without_creating_transaction(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);

        Volt::test('banking.transactions.index')
            ->call('create', 'expense')
            ->set('formDate', '2026-11-05')
            ->set('formAmount', '150.50')
            ->set('formDescription', 'Jantar com Ana')
            ->set('formBankAccountId', $account->id)
            ->call('payForSomeone')
            ->assertRedirect(route('banking.receivables.index', [
                'criar' => '1',
                'origin' => 'bank',
                'date' => '2026-11-05',
                'amount' => '150.50',
                'description' => 'Jantar com Ana',
                'installments' => 1,
                'bank_account_id' => $account->id,
            ]));

        $this->assertSame(0, Transaction::count());

        $this->get(route('banking.receivables.index', [
            'criar' => '1',
            'origin' => 'bank',
            'date' => '2026-11-05',
            'amount' => '150.50',
            'description' => 'Jantar com Ana',
            'bank_account_id' => $account->id,
        ]))->assertOk()->assertSee('Novo valor a receber')->assertSee('Jantar com Ana');
    }

    public function test_existing_expense_shortcut_opens_receivables_with_transaction_id(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);
        $tx = Transaction::create([
            'type' => 'expense', 'status' => 'settled', 'date' => '2026-11-05', 'amount' => 80,
            'description' => 'Uber compartilhado', 'bank_account_id' => $account->id,
        ]);

        Volt::test('banking.transactions.index')
            ->call('edit', $tx->id)
            ->assertSee('Paguei por alguém')
            ->call('payForSomeone')
            ->assertRedirect(route('banking.receivables.index', ['transaction' => $tx->id]));

        $this->assertSame(1, Transaction::count());
    }

    public function test_card_expense_shortcut_prefills_card_origin(): void
    {
        $card = CreditCard::create(['name' => 'Nubank', 'limit' => 5000, 'closing_day' => 10, 'due_day' => 17, 'status' => true]);

        Volt::test('banking.transactions.index')
            ->call('create', 'card_expense')
            ->set('formDate', '2026-11-05')
            ->set('formAmount', '200')
            ->set('formDescription', 'Mercado')
            ->set('formCreditCardId', $card->id)
            ->set('formInvoiceMonth', '2026-11')
            ->call('payForSomeone')
            ->assertRedirect(route('banking.receivables.index', [
                'criar' => '1',
                'origin' => 'card',
                'date' => '2026-11-05',
                'amount' => '200.00',
                'description' => 'Mercado',
                'installments' => 1,
                'credit_card_id' => $card->id,
            ]));
    }
}

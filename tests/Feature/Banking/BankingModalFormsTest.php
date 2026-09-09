<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BankingModalFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_modal_creates_and_edits_an_account(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('banking.accounts.index')
            ->assertSet('showFormModal', false)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('name', 'Conta operacional')
            ->set('bank', 'Banco do Brasil')
            ->set('type', 'checking')
            ->set('initial_balance', '500.25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $account = BankAccount::where('name', 'Conta operacional')->firstOrFail();

        Volt::test('banking.accounts.index')
            ->call('edit', $account->id)
            ->assertSet('showFormModal', true)
            ->assertSet('editingId', $account->id)
            ->set('name', 'Conta operacional atualizada')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('bank_accounts', [
            'id' => $account->id,
            'name' => 'Conta operacional atualizada',
            'initial_balance' => 500.25,
        ]);
    }

    public function test_card_modal_creates_and_edits_a_card(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta de pagamento']);

        Volt::test('banking.cards.index')
            ->assertSet('showFormModal', false)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('name', 'Cartão principal')
            ->set('brand', 'Visa')
            ->set('limit', '8000')
            ->set('closing_day', 20)
            ->set('due_day', 28)
            ->set('default_payment_account_id', $account->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $card = CreditCard::where('name', 'Cartão principal')->firstOrFail();

        Volt::test('banking.cards.index')
            ->call('edit', $card->id)
            ->assertSet('showFormModal', true)
            ->assertSet('editingId', $card->id)
            ->set('limit', '9500')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('credit_cards', [
            'id' => $card->id,
            'limit' => 9500,
            'default_payment_account_id' => $account->id,
        ]);
    }

    public function test_recurring_modal_creates_and_edits_a_recurring_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta mensal']);

        Volt::test('banking.recurring.index')
            ->assertSet('showFormModal', false)
            ->call('create')
            ->assertSet('showFormModal', true)
            ->set('type', 'expense')
            ->set('description', 'Aluguel')
            ->set('amount', '1800')
            ->set('bank_account_id', $account->id)
            ->set('frequency', 'monthly')
            ->set('day_of_month', 10)
            ->set('start_date', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $recurring = RecurringTransaction::where('description', 'Aluguel')->firstOrFail();

        Volt::test('banking.recurring.index')
            ->call('edit', $recurring->id)
            ->assertSet('showFormModal', true)
            ->assertSet('editingId', $recurring->id)
            ->set('amount', '1950')
            ->set('rec_status', 'paused')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('recurring_transactions', [
            'id' => $recurring->id,
            'amount' => 1950,
            'status' => 'paused',
        ]);
    }
}

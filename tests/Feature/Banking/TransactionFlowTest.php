<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\RecurringTransaction;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\RecurringTransactionService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransactionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_index_opens_and_closes_creation_modal(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Volt::test('banking.transactions.index')
            ->assertSet('showFormModal', false);

        foreach (['expense' => 'Nova despesa', 'income' => 'Nova receita', 'transfer' => 'Nova transferência'] as $type => $title) {
            $component
                ->call('create', $type)
                ->assertSet('showFormModal', true)
                ->assertSet('formType', $type)
                ->assertSet('formDate', now()->format('Y-m-d'))
                ->assertSet('formDescription', '')
                ->assertSee($title)
                ->assertDontSeeHtml('wire:model.live="formType"')
                ->set('formDescription', 'Rascunho descartado')
                ->call('cancel')
                ->assertSet('showFormModal', false);
        }
    }

    public function test_transaction_action_buttons_are_inside_the_livewire_root(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('banking.transactions.index'));
        $response->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        foreach (['Importar OFX', 'Novo lançamento'] as $label) {
            $button = $xpath->query('//button[contains(normalize-space(.), "'.$label.'")]')->item(0);
            $this->assertNotNull($button, "Botão {$label} não encontrado.");

            $parent = $button;

            while ($parent && ! ($parent instanceof \DOMElement && $parent->hasAttribute('wire:id'))) {
                $parent = $parent->parentNode;
            }

            $this->assertInstanceOf(\DOMElement::class, $parent, "Botão {$label} fora da raiz Livewire.");
        }
    }

    public function test_transaction_modal_creates_a_manual_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta principal', 'initial_balance' => 0]);
        $category = Category::create(['name' => 'Honorários', 'type' => 'income', 'status' => true]);

        Volt::test('banking.transactions.index')
            ->call('create', 'income')
            ->set('formDate', '2026-09-09')
            ->set('formAmount', '1250.50')
            ->set('formDescription', 'Recebimento de honorários')
            ->set('formStatus', 'settled')
            ->set('formCategoryId', $category->id)
            ->set('formBankAccountId', $account->id)
            ->call('saveTransaction')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('transactions', [
            'type' => 'income',
            'date' => '2026-09-09 00:00:00',
            'amount' => 1250.50,
            'description' => 'Recebimento de honorários',
            'category_id' => $category->id,
            'bank_account_id' => $account->id,
        ]);
    }

    public function test_transaction_modal_edits_a_manual_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $transaction = Transaction::create([
            'type' => 'expense',
            'date' => '2026-09-08',
            'amount' => 100,
            'description' => 'Despesa antiga',
            'status' => 'pending',
        ]);

        Volt::test('banking.transactions.index')
            ->call('edit', $transaction->id)
            ->assertSet('showFormModal', true)
            ->assertSet('editingId', $transaction->id)
            ->set('formAmount', '175.90')
            ->set('formDescription', 'Despesa atualizada')
            ->set('formStatus', 'settled')
            ->call('saveTransaction')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'amount' => 175.90,
            'description' => 'Despesa atualizada',
            'status' => 'settled',
        ]);
    }

    public function test_transaction_categories_match_the_type_including_card_expenses(): void
    {
        $this->actingAs(User::factory()->create());
        $income = Category::create(['name' => 'Honorários', 'type' => 'income', 'status' => true]);
        $expense = Category::create(['name' => 'Alimentação', 'type' => 'expense', 'status' => true]);

        foreach (['income', 'expense', 'card_expense'] as $type) {
            $expected = $type === 'income' ? $income : $expense;
            $wrong = $type === 'income' ? $expense : $income;
            $component = Volt::test('banking.transactions.index')->call('create', $type);
            $document = new \DOMDocument;
            @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new \DOMXPath($document);
            $options = $xpath->query('//select[@*[name()="wire:model" and .="formCategoryId"]]/option');
            $values = [];
            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }
            $this->assertEquals(['', (string) $expected->id], $values);

            $component->set('formCategoryId', $wrong->id)
                ->set('formAmount', '100.00')
                ->set('formDescription', 'Lançamento')
                ->call('saveTransaction')
                ->assertHasErrors(['formCategoryId']);
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_payment_switch_defaults_follow_the_date_and_allow_manual_override(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::parse('2026-10-07 01:00:00', 'UTC'));

        foreach (['expense', 'income'] as $type) {
            Volt::test('banking.transactions.index')
                ->call('create', $type)
                ->assertSet('formDate', '2026-10-06')
                ->assertSet('formStatus', 'pending')
                ->assertSee($type === 'income' ? 'Já foi recebido' : 'Já foi pago')
                ->set('formDate', '2026-10-05')
                ->assertSet('formStatus', 'settled')
                ->set('formDate', '2026-10-06')
                ->assertSet('formStatus', 'pending')
                ->set('formDate', '2026-10-08')
                ->assertSet('formStatus', 'pending')
                ->set('formStatus', 'settled')
                ->set('formAmount', '100.00')
                ->set('formDescription', 'Pagamento manual')
                ->call('saveTransaction')
                ->assertHasNoErrors();
            $this->assertDatabaseHas('transactions', ['type' => $type, 'date' => '2026-10-08 00:00:00', 'status' => 'settled']);
        }
    }

    public function test_transfer_switch_status_is_applied_to_both_sides_on_creation_and_edit(): void
    {
        $this->actingAs(User::factory()->create());
        $origin = BankAccount::create(['name' => 'Origem', 'initial_balance' => 1000]);
        $destination = BankAccount::create(['name' => 'Destino', 'initial_balance' => 0]);

        $component = Volt::test('banking.transactions.index')
            ->call('create', 'transfer')
            ->assertSet('formStatus', 'pending')
            ->set('formBankAccountId', $origin->id)
            ->set('formTransferToId', $destination->id)
            ->set('formAmount', '100.00')
            ->set('formDescription', 'Transferência pendente')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertEquals(2, Transaction::where('type', 'transfer')->where('status', 'pending')->count());
        $transaction = Transaction::where('bank_account_id', $destination->id)->firstOrFail();
        $component->call('edit', $transaction->id)
            ->assertSet('formStatus', 'pending')
            ->set('formStatus', 'settled')
            ->call('saveTransaction')
            ->assertHasNoErrors();
        $this->assertEquals(2, Transaction::where('type', 'transfer')->where('status', 'settled')->count());
    }

    public function test_card_purchase_requires_an_active_card_and_has_its_own_form(): void
    {
        $this->actingAs(User::factory()->create());
        $card = CreditCard::create(['name' => 'Inativo', 'status' => false, 'limit' => 1000, 'closing_day' => 25, 'due_day' => 5]);

        $component = Volt::test('banking.transactions.index')
            ->call('create', 'card_expense')
            ->assertSet('formType', 'expense')
            ->assertSee('Nova despesa no cartão de crédito')
            ->assertDontSeeHtml('wire:model="formBankAccountId"')
            ->assertDontSeeHtml('wire:model="formStatus"')
            ->set('formAmount', '100.00')
            ->set('formDescription', 'Compra')
            ->call('saveTransaction')
            ->assertHasErrors(['formCreditCardId', 'formInvoiceMonth']);

        $component->set('formCreditCardId', $card->id)
            ->call('saveTransaction')
            ->assertHasErrors(['formCreditCardId']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_card_purchase_splits_into_selected_monthly_invoices_without_debiting_account(): void
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5, 'default_payment_account_id' => $account->id]);

        Volt::test('banking.transactions.index')
            ->call('create', 'card_expense')
            ->set('formDate', '2026-10-26')
            ->set('formCreditCardId', $card->id)
            ->assertSet('formInvoiceMonth', '2026-11')
            ->set('formInvoiceMonth', '2026-12')
            ->set('formAmount', '100.00')
            ->set('formDescription', 'Compra parcelada')
            ->set('formInstallments', 3)
            ->set('formBankAccountId', $account->id)
            ->call('saveTransaction')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $transactions = Transaction::where('credit_card_id', $card->id)->orderBy('installment_number')->get();
        $this->assertCount(3, $transactions);
        $this->assertEquals([33.33, 33.33, 33.34], $transactions->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        $this->assertTrue($transactions->every(fn ($transaction) => $transaction->type === 'expense' && $transaction->bank_account_id === null));
        $invoices = $card->invoices()->orderBy('reference_month')->get();
        $this->assertEquals(['2026-12', '2027-01', '2027-02'], $invoices->pluck('reference_month')->all());
        $this->assertEquals('2027-01-05', $invoices->first()->due_date->format('Y-m-d'));
        $this->assertEquals(100, $invoices->sum('total'));
        $this->assertEquals(1000, $account->fresh()->balance());
    }

    public function test_editing_card_expense_moves_it_to_the_correct_invoice_and_recalculates_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        $other = CreditCard::create(['name' => 'Mastercard', 'limit' => 5000, 'closing_day' => 20, 'due_day' => 28]);
        $transaction = app(InstallmentService::class)->split($card, Carbon::parse('2026-10-10'), 100, 1, 'Compra')[0];
        $oldInvoice = $transaction->invoice;

        Volt::test('banking.transactions.index')
            ->call('edit', $transaction->id)
            ->assertSee('Editar despesa no cartão')
            ->assertSet('formInvoiceMonth', '2026-10')
            ->set('formCreditCardId', $other->id)
            ->set('formInvoiceMonth', '2026-11')
            ->set('formAmount', '150.00')
            ->call('saveTransaction')
            ->assertHasNoErrors();

        $this->assertEquals(0, (float) $oldInvoice->fresh()->total);
        $transaction->refresh();
        $this->assertEquals($other->id, $transaction->credit_card_id);
        $this->assertEquals('2026-11', $transaction->invoice->reference_month);
        $this->assertEquals(150, (float) $transaction->invoice->total);
        $this->assertNull($transaction->bank_account_id);
    }

    public function test_creates_simple_income_and_expense(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100]);
        $category = Category::create(['name' => 'Salário', 'type' => 'income']);

        $service = app(TransactionService::class);
        $service->create([
            'type' => 'income',
            'date' => '2026-04-01',
            'amount' => 1000,
            'description' => 'Salário',
            'category_id' => $category->id,
            'bank_account_id' => $account->id,
        ]);
        $service->create([
            'type' => 'expense',
            'date' => '2026-04-02',
            'amount' => 200,
            'description' => 'Mercado',
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(900.0, $account->fresh()->balance());
    }

    public function test_transfer_creates_two_linked_transactions(): void
    {
        $a = BankAccount::create(['name' => 'A', 'initial_balance' => 1000]);
        $b = BankAccount::create(['name' => 'B', 'initial_balance' => 0]);

        app(TransferService::class)->execute($a, $b, 300, '2026-04-10');

        $this->assertEquals(700.0, $a->fresh()->balance());
        $this->assertEquals(300.0, $b->fresh()->balance());

        $this->assertEquals(2, Transaction::where('type', 'transfer')->count());
        $out = Transaction::where('bank_account_id', $a->id)->where('type', 'transfer')->first();
        $in = Transaction::where('bank_account_id', $b->id)->where('type', 'transfer')->first();
        $this->assertEquals($in->id, $out->related_transaction_id);
        $this->assertEquals($out->id, $in->related_transaction_id);
    }

    public function test_installments_split_across_invoices(): void
    {
        $card = CreditCard::create([
            'name' => 'Visa', 'limit' => 10000,
            'closing_day' => 25, 'due_day' => 5,
        ]);

        app(InstallmentService::class)->split(
            $card, Carbon::parse('2026-04-10'), 600, 3, 'Notebook'
        );

        $this->assertEquals(3, Transaction::where('credit_card_id', $card->id)->count());
        $this->assertEquals(3, CreditCardInvoice::where('credit_card_id', $card->id)->count());

        $invoices = CreditCardInvoice::where('credit_card_id', $card->id)->orderBy('reference_month')->get();
        foreach ($invoices as $inv) {
            $this->assertEquals(200.0, (float) $inv->total);
        }
    }

    public function test_invoice_payment_debits_account_and_updates_status(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);

        app(InstallmentService::class)->split($card, Carbon::parse('2026-04-10'), 300, 1, 'Compra');
        $invoice = CreditCardInvoice::first();
        app(InvoiceService::class)->closeInvoice($invoice);

        app(InvoicePaymentService::class)->pay($invoice->fresh(), $account, 300, '2026-04-15');

        $this->assertEquals('paid', $invoice->fresh()->status);
        $this->assertEquals(700.0, $account->fresh()->balance());
    }

    public function test_recurring_generates_today(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);
        $today = Carbon::today();

        RecurringTransaction::create([
            'type' => 'income',
            'description' => 'Aluguel',
            'amount' => 500,
            'bank_account_id' => $account->id,
            'frequency' => 'monthly',
            'day_of_month' => $today->day,
            'start_date' => $today->copy()->subMonth(),
            'status' => 'active',
        ]);

        $count = app(RecurringTransactionService::class)->generateForToday($today);

        $this->assertEquals(1, $count);
        $this->assertEquals(500.0, $account->fresh()->balance());

        $count2 = app(RecurringTransactionService::class)->generateForToday($today);
        $this->assertEquals(0, $count2, 'não deve duplicar no mesmo dia');
    }

    public function test_source_typed_transaction_is_read_only(): void
    {
        $t = Transaction::create([
            'type' => 'expense', 'date' => '2026-04-01', 'amount' => 100,
            'description' => 'Origem externa', 'status' => 'settled',
            'source_type' => 'App\\Domains\\Brokers\\Models\\Commission',
            'source_id' => 999,
        ]);

        $this->assertTrue($t->isReadOnly());

        $this->expectException(\DomainException::class);
        app(TransactionService::class)->update($t, ['amount' => 200]);
    }

    public function test_invoice_closing_marks_status_closed(): void
    {
        $card = CreditCard::create(['name' => 'Card', 'limit' => 1000, 'closing_day' => 25, 'due_day' => 5]);
        app(InstallmentService::class)->split($card, Carbon::parse('2026-04-10'), 100, 1, 'Compra');
        $invoice = CreditCardInvoice::first();

        app(InvoiceService::class)->closeInvoice($invoice);

        $this->assertEquals('closed', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->closed_at);
    }
}

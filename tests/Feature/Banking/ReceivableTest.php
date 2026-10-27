<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\ReceivableSettlement;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\ReceivableService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Dashboard\Services\DashboardSnapshotBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReceivableTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    private Contact $person;

    private ReceivableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->bank = BankAccount::create(['name' => 'Banco', 'initial_balance' => 20000, 'status' => true]);
        $this->person = Contact::create(['name' => 'Ana', 'type' => 'pessoal', 'status' => true]);
        $this->service = app(ReceivableService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'origin' => 'bank', 'date' => today()->toDateString(), 'description' => 'Adiantamento para Ana', 'amount' => '10000.00',
            'bank_account_id' => $this->bank->id, 'installments' => 1, 'repayment_mode' => 'process',
            'request_key' => (string) Str::uuid(), 'people' => [['contact_id' => $this->person->id, 'amount' => '10000.00']],
        ], $overrides);
    }

    private function totals(): array
    {
        return app(OwnFinancialService::class)->totals(today()->startOfMonth(), today()->endOfMonth());
    }

    public function test_client_advance_moves_cash_without_becoming_expense_and_can_be_repaid_partially(): void
    {
        $row = $this->service->create($this->data())[0];
        $this->assertEquals(10000, $this->bank->balance());
        $this->assertSame(1000000, $row->remainingCents());
        $this->assertEquals(0, $this->totals()['expense']);
        $key = (string) Str::uuid();
        $this->service->receive($row, '3000.00', today()->toDateString(), $this->bank->id, $key);
        $this->service->receive($row, '3000.00', today()->toDateString(), $this->bank->id, $key);
        $this->assertEquals(13000, $this->bank->balance());
        $this->assertSame(700000, $row->fresh()->remainingCents());
        $this->assertEquals(0, $this->totals()['income']);
        $this->assertSame(1, ReceivableSettlement::count());
        $this->service->undo($row, $key);
        $this->assertEquals(10000, $this->bank->balance());
        $this->assertSame(1000000, $row->fresh()->remainingCents());
    }

    public function test_dinner_can_be_shared_with_several_people_and_only_own_share_is_expense(): void
    {
        $friend = Contact::create(['name' => 'Pedro', 'status' => true]);
        $data = $this->data(['amount' => '600.00', 'people' => [
            ['contact_id' => $this->person->id, 'amount' => '200.00'], ['contact_id' => $friend->id, 'amount' => '200.00'],
        ]]);
        $rows = $this->service->create($data);
        $this->service->create($data);
        $this->assertCount(2, $rows);
        $this->assertSame(1, Transaction::count());
        $this->assertEquals(19400, $this->bank->balance());
        $this->assertEquals(200, $this->totals()['expense']);
        $this->service->receive($rows[0], '200.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $this->assertEquals(0, $this->totals()['income']);
        $this->assertEquals(200, $this->totals()['expense']);
    }

    public function test_card_purchase_keeps_debt_and_invoice_payments_independent(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5, 'status' => true]);
        $row = $this->service->create($this->data(['origin' => 'card', 'credit_card_id' => $card->id, 'amount' => '3000.00', 'installments' => 10,
            'repayment_mode' => 'dated', 'first_due_date' => '2026-11-04', 'people' => [['contact_id' => $this->person->id, 'amount' => '3000.00']]]))[0];
        $this->assertCount(10, $row->installments);
        $this->assertEquals(20000, $this->bank->balance());
        $this->assertSame('2026-11-04', $row->installments[0]->due_date->toDateString());
        $this->assertSame('2026-12-04', $row->installments[1]->due_date->toDateString());
        $invoice = $row->installments[0]->transaction->invoice;
        app(InvoicePaymentService::class)->pay($invoice, $this->bank, 300, today());
        $this->assertEquals(19700, $this->bank->balance());
        $this->assertSame(300000, $row->fresh()->remainingCents());
        $this->assertEquals(0, $this->totals()['expense']);
        $this->service->receive($row, '600.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $this->assertSame(240000, $row->fresh()->remainingCents());
        $this->assertEquals(300, $invoice->fresh()->paid_amount);
        $this->assertEquals(0, $this->totals()['income']);
    }

    public function test_mixed_card_bill_is_counted_once_and_partial_payments_are_proportional(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5, 'status' => true]);
        $row = $this->service->create($this->data(['origin' => 'card', 'credit_card_id' => $card->id, 'amount' => '400.00',
            'people' => [['contact_id' => $this->person->id, 'amount' => '200.00']]]))[0];
        $invoice = $row->installments[0]->transaction->invoice;
        $this->assertEquals(0, $this->totals()['expense']);
        app(InvoicePaymentService::class)->pay($invoice, $this->bank, 100, today());
        $this->assertEquals(50, $this->totals()['expense']);
        app(InvoicePaymentService::class)->pay($invoice->fresh(), $this->bank, 300, today());
        $this->assertEquals(200, $this->totals()['expense']);
        $this->assertSame(20000, $row->fresh()->remainingCents());
    }

    public function test_existing_outgoing_and_incoming_entries_are_reclassified_without_duplication(): void
    {
        $source = app(TransactionService::class)->create(['type' => 'expense', 'amount' => '400.00', 'date' => today(), 'description' => 'Jantar', 'bank_account_id' => $this->bank->id]);
        $row = $this->service->create($this->data(['origin' => 'existing', 'transaction_id' => $source->id, 'amount' => '400.00', 'people' => [['contact_id' => $this->person->id, 'amount' => '200.00']]]))[0];
        $incoming = app(TransactionService::class)->create(['type' => 'income', 'amount' => '200.00', 'date' => today(), 'description' => 'PIX Ana', 'bank_account_id' => $this->bank->id]);
        $key = (string) Str::uuid();
        $this->service->receive($row, '200.00', today()->toDateString(), $this->bank->id, $key, $incoming->id);
        $this->assertSame(2, Transaction::count());
        $this->assertEquals(19800, $this->bank->balance());
        $this->assertEquals(0, $this->totals()['income']);
        $this->service->undo($row, $key);
        $this->assertNotNull($incoming->fresh());
        $this->assertEquals(200, $this->totals()['income']);
    }

    public function test_existing_card_group_allocates_exact_cents_without_creating_new_purchases(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5, 'status' => true]);
        $purchases = app(InstallmentService::class)->split($card, today(), 100, 3, 'Compra');
        $row = $this->service->create($this->data(['origin' => 'existing', 'transaction_id' => $purchases[1]->id, 'amount' => '100.00', 'people' => [['contact_id' => $this->person->id, 'amount' => '100.00']]]))[0];
        $this->assertSame(10000, $row->amountCents());
        $this->assertCount(3, $row->installments);
        $this->assertSame(3, Transaction::count());
        foreach ($row->installments as $part) {
            $this->assertEquals($part->transaction->amount, $part->amount);
        }
    }

    public function test_client_offset_reduces_repasse_without_new_bank_income_and_can_be_undone(): void
    {
        $row = $this->service->create($this->data())[0];
        $clientAccount = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $receipt = app(TransactionService::class)->create(['type' => 'income', 'amount' => '50000.00', 'date' => today(), 'description' => 'Processo', 'bank_account_id' => $this->bank->id]);
        app(ProcessReceiptService::class)->save($receipt, [
            'broker_id' => null, 'client_name' => 'Ana', 'client_contact_id' => $this->person->id,
            'fees_mode' => 'amount', 'fees' => '15000.00', 'commission_mode' => 'amount', 'broker_amount' => '0.00', 'office_amount' => '0.00', 'expenses' => [], 'advances' => [],
            'receivables' => [$row->id => '10000.00'],
        ]);
        $part = $receipt->destinations()->where('kind', 'client')->sole();
        $this->assertSame(2500000, $part->remainingCents());
        $this->assertEquals(-25000, $clientAccount->balance());
        $this->assertSame(0, $row->fresh()->remainingCents());
        $this->assertEquals(60000, $this->bank->balance());
        $this->assertEquals(15000, $this->totals()['income']);
        $this->assertSame(1, Transaction::where('type', 'income')->count());
        $key = $row->settlements()->first()->batch_key;
        $this->service->undo($row, $key);
        $this->assertEquals(-35000, $clientAccount->balance());
        $this->assertSame(1000000, $row->fresh()->remainingCents());
        $this->assertEquals(60000, $this->bank->balance());
    }

    public function test_offset_checks_same_contact_and_available_client_share(): void
    {
        $row = $this->service->create($this->data())[0];
        $other = Contact::create(['name' => 'Outro', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'status' => 'settled', 'amount' => 100, 'date' => today(), 'description' => 'Processo', 'bank_account_id' => $this->bank->id]);
        $part = ReceiptDestination::create(['receipt_id' => $receipt->id, 'kind' => 'client', 'beneficiary' => 'Outro', 'amount' => 100, 'contact_id' => $other->id]);
        try {
            $this->service->offset($row, $part, '100.00', (string) Str::uuid());
            $this->fail();
        } catch (\DomainException $e) {
            $this->assertStringContainsString('mesmo contato', $e->getMessage());
        }
        $part->update(['contact_id' => $this->person->id]);
        $this->expectException(\DomainException::class);
        $this->service->offset($row, $part, '1000.00', (string) Str::uuid());
    }

    public function test_overallocation_and_overpayment_roll_back(): void
    {
        try {
            $this->service->create($this->data(['amount' => '100.00']));
            $this->fail();
        } catch (\DomainException) {
            $this->assertSame(0, Transaction::count());
        }
        $row = $this->service->create($this->data())[0];
        try {
            $this->service->receive($row, '10001.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
            $this->fail();
        } catch (\DomainException) {
            $this->assertSame(1, Transaction::count());
        }
        $this->assertSame(0, ReceivableSettlement::count());
    }

    public function test_source_cannot_be_changed_deleted_or_reused_as_repasse(): void
    {
        $row = $this->service->create($this->data())[0];
        $source = $row->installments[0]->transaction;
        try {
            app(TransactionService::class)->update($source, ['amount' => '2.00']);
            $this->fail();
        } catch (\DomainException) {
            $this->assertEquals(10000, $source->fresh()->amount);
        }
        try {
            app(TransactionService::class)->delete($source);
            $this->fail();
        } catch (\DomainException) {
            $this->assertNotNull($source->fresh());
        }
        $this->assertFalse($this->person->canBeDeleted());
        $this->service->detach($row);
        $this->assertEquals(10000, $this->totals()['expense']);
        app(TransactionService::class)->delete($source->fresh());
        $this->assertSoftDeleted($source);
    }

    public function test_undated_values_are_not_overdue_and_dates_can_be_changed(): void
    {
        $row = $this->service->create($this->data(['date' => '2026-01-01']))[0];
        $this->assertSame('Em aberto', $row->statusLabel());
        $part = $row->installments[0];
        $this->service->reschedule($row, [$part->id => '2026-10-01']);
        $this->assertSame('Vencido', $row->fresh()->statusLabel());
        $this->service->reschedule($row, [$part->id => '']);
        $this->assertSame('Em aberto', $row->fresh()->statusLabel());
    }

    public function test_explicit_loss_becomes_own_expense_without_another_bank_withdrawal(): void
    {
        $row = $this->service->create($this->data())[0];
        $key = (string) Str::uuid();
        $this->service->writeOff($row, '2000.00', today()->toDateString(), $key, 'Valor perdoado');
        $this->assertEquals(10000, $this->bank->balance());
        $this->assertEquals(2000, $this->totals()['expense']);
        $this->assertSame(800000, $row->fresh()->remainingCents());
        $this->service->undo($row, $key);
        $this->assertEquals(0, $this->totals()['expense']);
    }

    public function test_receivables_are_included_in_patrimony_without_changing_own_result(): void
    {
        $this->service->create($this->data());
        $payload = app(DashboardSnapshotBuilder::class)->build();
        $this->assertEquals(10000, $payload['patrimony']['third_party_receivable']);
        $this->assertEquals(20000, $payload['patrimony']['total']);
        $this->assertEquals(0, $payload['month']['result']);
    }

    public function test_workspace_renders_and_supports_creation_receipt_and_date_adjustment(): void
    {
        $this->get(route('banking.receivables.index'))->assertOk()->assertSee('Valores a receber');
        $component = Volt::test('banking.receivables.index')->call('startCreate')->set('form', $this->data())->call('save')->assertHasNoErrors()->assertSee('Adiantamento para Ana');
        $row = Receivable::sole();
        $component->set('payment.amount', '100.00')->set('payment.bank_account_id', $this->bank->id)->call('settle')->assertHasNoErrors()->assertSee('R$ 9.900,00');
        $this->get(route('contacts.show', $this->person))->assertOk()->assertSee('Valores a receber desta pessoa');
        Volt::test('banking.transactions.index')->assertSee('Dividir com terceiros')->assertSee('Devolução de adiantamento');
        Volt::test('banking.reports.cashflow')->assertSee('Receitas próprias')->assertSee('Variação do caixa');
    }

    public function test_card_repayments_can_use_actual_invoice_dates(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5, 'status' => true]);
        $row = $this->service->create($this->data(['origin' => 'card', 'credit_card_id' => $card->id, 'amount' => '100.00', 'installments' => 3,
            'repayment_mode' => 'dated', 'use_invoice_dates' => true, 'first_due_date' => null,
            'people' => [['contact_id' => $this->person->id, 'amount' => '100.00']]]))[0];
        foreach ($row->installments as $part) {
            $this->assertSame($part->transaction->invoice->due_date->toDateString(), $part->due_date->toDateString());
        }
        $this->assertSame(10000, $row->amountCents());
    }

    public function test_selecting_one_installment_does_not_settle_others_and_future_payments_are_rejected(): void
    {
        $row = $this->service->create($this->data(['installments' => 3, 'amount' => '300.00', 'people' => [['contact_id' => $this->person->id, 'amount' => '300.00']]]))[0];
        $this->service->receive($row, '50.00', today()->toDateString(), $this->bank->id, (string) Str::uuid(), installmentId: $row->installments[2]->id);
        $parts = $row->fresh()->installments;
        $this->assertSame([10000, 10000, 5000], $parts->map(fn ($part) => $part->remainingCents())->all());
        $this->expectException(ValidationException::class);
        $this->service->receive($row, '10.00', today()->addDay()->toDateString(), $this->bank->id, (string) Str::uuid());
    }

    public function test_invoice_payment_from_previous_month_is_reclassified_and_undo_is_guarded(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5, 'status' => true]);
        $source = app(InstallmentService::class)->split($card, today()->subMonth(), 400, 1, 'Compra antiga')[0];
        app(InvoicePaymentService::class)->pay($source->invoice, $this->bank, 400, today()->subMonth());
        $previous = today()->subMonth();
        $own = app(OwnFinancialService::class);
        $this->assertEquals(400, $own->totals($previous->copy()->startOfMonth(), $previous->copy()->endOfMonth())['expense']);
        $row = $this->service->create($this->data(['origin' => 'existing', 'transaction_id' => $source->id, 'amount' => '400.00', 'people' => [['contact_id' => $this->person->id, 'amount' => '200.00']]]))[0];
        $this->assertEquals(200, $own->totals($previous->copy()->startOfMonth(), $previous->copy()->endOfMonth())['expense']);
        $this->service->receive($row, '100.00', today()->toDateString(), $this->bank->id, (string) Str::uuid());
        $this->expectException(\DomainException::class);
        $this->service->detach($row);
    }

    public function test_imported_refund_cannot_be_used_as_process_receipt_while_linked(): void
    {
        $row = $this->service->create($this->data())[0];
        $income = Transaction::create(['type' => 'income', 'status' => 'settled', 'date' => today(), 'amount' => '100.00', 'description' => 'PIX', 'bank_account_id' => $this->bank->id]);
        $this->service->receive($row, '100.00', today()->toDateString(), $this->bank->id, (string) Str::uuid(), $income->id);
        $this->expectException(\DomainException::class);
        app(ReceiptDestinationService::class)->eligible($income->fresh());
    }

    public function test_process_form_lists_client_advances_and_reports_only_own_values_by_category(): void
    {
        $row = $this->service->create($this->data())[0];
        BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'status' => 'settled', 'date' => today(), 'amount' => '50000.00', 'description' => 'Processo Ana', 'bank_account_id' => $this->bank->id]);
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->set('form.client_contact_id', $this->person->id)->assertSee('Adiantamento para Ana')
            ->set('form.fees', '15000.00')->set('form.receivables.'.$row->id, '10000.00')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, $row->fresh()->remainingCents());
        $report = Volt::test('banking.reports.cashflow');
        $this->assertEquals(15000, $report->viewData('ownIncome'));
        $this->assertEquals(0, $report->viewData('ownExpense'));
        $categories = $report->viewData('byCategory');
        $this->assertEquals(15000, $categories->sum('own_income'));
        $this->assertEquals(0, $categories->sum('own_expense'));
        $this->assertEquals(10000, $categories->sum('third_party_out'));
    }

    public function test_inline_contact_creation_keeps_the_payment_draft_and_marks_contact_as_personal(): void
    {
        $component = Volt::test('banking.receivables.index')->call('startCreate')->set('form.description', 'Jantar')
            ->set('form.people.0.amount', '200.00')
            ->set('newContactName', 'Amigo novo')->call('createPersonalContact')->assertHasNoErrors()
            ->assertSet('form.description', 'Jantar')->assertSet('form.people.0.amount', '200.00')->assertSee('Amigo novo');
        $contact = Contact::where('name', 'Amigo novo')->sole();
        $this->assertSame('pessoal', $contact->type);
        $component->assertSet('form.people.0.contact_id', $contact->id)->assertSet('newContactName', '');
    }

    public function test_new_contact_uses_an_empty_person_row_and_preserves_people_already_selected(): void
    {
        $component = Volt::test('banking.receivables.index')->call('startCreate')
            ->set('form.people.0.contact_id', $this->person->id)->set('form.people.0.amount', '150.00')
            ->call('addPerson')->set('form.people.1.amount', '200.00')
            ->set('newContactName', 'Segundo amigo')->call('createPersonalContact')->assertHasNoErrors();
        $contact = Contact::where('name', 'Segundo amigo')->sole();
        $component->assertSet('form.people.0.contact_id', $this->person->id)
            ->assertSet('form.people.0.amount', '150.00')
            ->assertSet('form.people.1.contact_id', $contact->id)->assertSet('form.people.1.amount', '200.00');
        $component->set('newContactName', 'Terceiro amigo')->call('createPersonalContact')->assertHasNoErrors();
        $third = Contact::where('name', 'Terceiro amigo')->sole();
        $component->assertSet('form.people.0.contact_id', $this->person->id)
            ->assertSet('form.people.1.contact_id', $contact->id)->assertSet('form.people.2.contact_id', $third->id);
    }
}

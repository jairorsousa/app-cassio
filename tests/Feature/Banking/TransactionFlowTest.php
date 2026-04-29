<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\RecurringTransactionService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionFlowTest extends TestCase
{
    use RefreshDatabase;

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

        \App\Domains\Banking\Models\RecurringTransaction::create([
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

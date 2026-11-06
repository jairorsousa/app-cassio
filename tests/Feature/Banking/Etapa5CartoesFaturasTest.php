<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Jobs\CloseInvoiceJob;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa5CartoesFaturasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-20 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = []): CreditCard
    {
        return CreditCard::create(array_merge(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5], $attrs));
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['name' => 'Conta BB', 'initial_balance' => 1000])->fresh();
    }

    private function closedInvoice(CreditCard $card, float $amount = 300): CreditCardInvoice
    {
        app(InstallmentService::class)->split($card, Carbon::parse('2026-04-10'), $amount, 1, 'Compra');
        $invoice = CreditCardInvoice::where('credit_card_id', $card->id)->first();

        return app(InvoiceService::class)->closeInvoice($invoice);
    }

    public function test_old_cards_without_holder_are_flagged_not_invented(): void
    {
        $card = $this->card(['name' => 'BTG Luciane 2043']);

        $this->assertFalse($card->hasHolder());
        $this->assertSame('Não informado', $card->holderLabel());
        $this->assertSame('none', $card->holderKey());
    }

    public function test_card_form_saves_each_holder_type_and_validates(): void
    {
        $contact = Contact::create(['name' => 'Maria Contato', 'type' => 'pessoal']);

        Volt::test('banking.cards.index')->call('create')
            ->set('name', 'Filho card')->set('closing_day', 10)->set('due_day', 20)
            ->set('holder_type', 'filho')->set('holder_name', '')
            ->call('save')->assertHasErrors(['holder_name'])
            ->set('holder_name', 'Isabela')->call('save')->assertHasNoErrors();

        Volt::test('banking.cards.index')->call('create')
            ->set('name', 'Contato card')->set('closing_day', 10)->set('due_day', 20)
            ->set('holder_type', 'contato')->call('save')->assertHasErrors(['holder_contact_id'])
            ->set('holder_contact_id', $contact->id)->call('save')->assertHasNoErrors();

        Volt::test('banking.cards.index')->call('create')
            ->set('name', 'Lu')->set('closing_day', 10)->set('due_day', 20)
            ->set('holder_type', 'luciane')->set('holder_name', 'lixo')->call('save')->assertHasNoErrors();

        $this->assertSame('Isabela (filho/a)', CreditCard::where('name', 'Filho card')->first()->holderLabel());
        $this->assertSame('Maria Contato', CreditCard::where('name', 'Contato card')->first()->holderLabel());
        $lu = CreditCard::where('name', 'Lu')->first();
        $this->assertSame('luciane', $lu->holderKey());
        $this->assertNull($lu->holder_name);
    }

    public function test_transactions_can_be_filtered_by_card_holder(): void
    {
        $lu = $this->card(['name' => 'Cartão Lu', 'holder_type' => 'luciane']);
        $me = $this->card(['name' => 'Cartão Cássio', 'holder_type' => 'cassio']);
        app(InstallmentService::class)->split($lu, Carbon::parse('2026-05-10'), 50, 1, 'Farmácia Lu');
        app(InstallmentService::class)->split($me, Carbon::parse('2026-05-10'), 70, 1, 'Posto Cássio');

        Volt::test('banking.transactions.index')->set('titular', 'luciane')
            ->assertSee('Farmácia Lu')->assertDontSee('Posto Cássio');
        Volt::test('banking.cards.index')->set('titular', 'cassio')
            ->assertSee('Cartão Cássio')->assertDontSee('Cartão Lu');
        $this->assertArrayHasKey('luciane', CreditCard::holderFilterOptions());
    }

    public function test_closed_and_overdue_invoices_show_pay_button(): void
    {
        $card = $this->card();
        $invoice = $this->closedInvoice($card);

        $this->assertSame('closed', $invoice->status);
        $this->assertTrue($invoice->isPayable());
        $this->assertSame('overdue', $invoice->fresh()->displayStatus(), 'venceu em 05/05');

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->assertSee('Vencida')->assertSee('Pagar')
            ->call('startPayment', $invoice->id)
            ->assertSet('payingInvoiceId', $invoice->id)
            ->assertSet('payment_amount', '300.00');
    }

    public function test_partial_then_total_payment_is_settlement_not_new_expense(): void
    {
        $card = $this->card();
        $account = $this->account();
        $invoice = $this->closedInvoice($card);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('startPayment', $invoice->id)
            ->set('payment_account_id', $account->id)->set('payment_amount', '100,00')->set('payment_date', '2026-05-06')
            ->call('pay')->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertEquals(200.0, $invoice->remainingAmount());

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('startPayment', $invoice->id)
            ->set('payment_account_id', $account->id)->set('payment_amount', '250')
            ->call('pay')->assertHasErrors(['payment_amount']);

        app(InvoicePaymentService::class)->pay($invoice, $account, 200, '2026-05-07', 'quitação');
        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertFalse($invoice->isPayable());
        $this->assertEquals(700.0, $account->fresh()->balance());
        $this->assertSame(1, Transaction::where('type', 'expense')->count(), 'compra lançada uma única vez');
        $this->assertSame(2, Transaction::where('type', 'invoice_payment')->count());
        $this->assertEquals(300.0, (float) $invoice->total, 'pagamento não altera o total da fatura');

        $this->expectException(\InvalidArgumentException::class);
        app(InvoicePaymentService::class)->pay($invoice, $account, 1, '2026-05-08');
    }

    public function test_regimes_count_card_purchase_once(): void
    {
        $card = $this->card();
        $account = $this->account();
        $invoice = $this->closedInvoice($card, 300);
        app(InvoicePaymentService::class)->pay($invoice, $account, 300, '2026-05-06');
        $own = app(OwnFinancialService::class);

        $aprilCash = $own->totals(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), 'cash');
        $mayCash = $own->totals(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), 'cash');
        $aprilAccrual = $own->totals(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), 'accrual');
        $mayAccrual = $own->totals(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), 'accrual');

        $this->assertEquals(0.0, $aprilCash['expense']);
        $this->assertEquals(300.0, $mayCash['expense'], 'caixa: pelo pagamento');
        $this->assertEquals(300.0, $aprilAccrual['expense'], 'competência: pela compra');
        $this->assertEquals(0.0, $mayAccrual['expense']);
    }

    public function test_deleting_payment_reopens_debt_and_new_purchase_updates_status(): void
    {
        $card = $this->card();
        $account = $this->account();
        $invoice = $this->closedInvoice($card);
        $payment = app(InvoicePaymentService::class)->pay($invoice, $account, 300, '2026-05-06');
        $this->assertSame('paid', $invoice->fresh()->status);

        $payment->delete();
        $invoice->refresh();
        $this->assertSame('closed', $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->paid_amount);
        $this->assertTrue($invoice->isPayable());
    }

    public function test_installments_spread_over_invoices_with_category_totals(): void
    {
        $card = $this->card();
        app(InstallmentService::class)->split($card, Carbon::parse('2026-04-10'), 900, 3, 'Notebook');

        $invoices = CreditCardInvoice::where('credit_card_id', $card->id)->orderBy('reference_month')->get();
        $this->assertCount(3, $invoices);
        $this->assertEquals([300.0, 300.0, 300.0], $invoices->map(fn ($i) => (float) $i->total)->all());

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('toggle', $invoices[0]->id)->assertSee('Notebook')->assertSee('(1/3)');
    }

    public function test_close_job_catches_up_missed_closing_dates(): void
    {
        $card = $this->card(['closing_day' => 15]);
        app(InstallmentService::class)->split($card, Carbon::parse('2026-05-10'), 80, 1, 'Mercado');
        $invoice = CreditCardInvoice::first();
        $this->assertSame('open', $invoice->status);

        (new CloseInvoiceJob)->handle(app(InvoiceService::class));

        $this->assertSame('closed', $invoice->fresh()->status, 'fechamento dia 15 já passou (hoje 20)');
    }
}

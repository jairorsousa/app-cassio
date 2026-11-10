<?php

namespace Tests\Feature;

use App\Domains\Assets\Models\Property;
use App\Domains\Assets\Models\PropertyPayment;
use App\Domains\Assets\Services\PropertyPaymentService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Banking\Services\Statements\StatementImportAnalyzer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PropertyPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $btg;

    private Property $watch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->btg = BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 63410, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        $this->watch = Property::create(['kind' => 'outro', 'description' => 'Relógio Rolex', 'acquired_at' => '2026-10-30', 'acquisition_value' => 70800, 'current_value' => 70800, 'status' => 'active', 'bank_account_id' => $this->btg->id]);
    }

    private function card(): CreditCard
    {
        return CreditCard::create(['name' => 'BTG 0052', 'limit' => 100000, 'closing_day' => 25, 'due_day' => 5]);
    }

    private function service(): PropertyPaymentService
    {
        return app(PropertyPaymentService::class);
    }

    public function test_category_is_system_transfer_and_created_by_migration(): void
    {
        $cat = Category::where('system_key', 'asset_purchase')->first();
        $this->assertNotNull($cat);
        $this->assertSame('Aquisição de bem', $cat->name);
        $this->assertSame('transfer', $cat->type);
        $this->assertSame($cat->id, Category::assetPurchaseCategoryId());
    }

    public function test_account_payment_creates_linked_statement_entry_and_lowers_balance(): void
    {
        $before = $this->btg->balance();
        $pay = $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id, 'notes' => 'Entrada']);

        $this->assertCount(1, $pay->transactions);
        $t = $pay->transactions->first();
        $this->assertSame('expense', $t->type);
        $this->assertSame('2026-10-30', $t->date->toDateString());
        $this->assertEquals(15800, (float) $t->amount);
        $this->assertSame(Category::assetPurchaseCategoryId(), $t->category_id);
        $this->assertSame(PropertyPayment::class, $t->source_type);
        $this->assertTrue($t->isReadOnly());
        $this->assertSame($this->watch->id, $t->propertyPaymentLink()->id);
        $this->assertEqualsWithDelta($before - 15800, $this->btg->fresh()->balance(), 0.001);

        $s = $this->watch->fresh()->paymentSummary();
        $this->assertEquals([70800.0, 15800.0, 0.0, 55000.0, 0.0, 22], [$s['total'], $s['paid'], $s['planned'], $s['remaining'], $s['over'], $s['percent']]);
    }

    public function test_asset_purchase_is_out_of_result_and_category_charts_in_both_regimes(): void
    {
        $card = $this->card();
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-20', 'amount' => '1000', 'method' => 'card', 'credit_card_id' => $card->id]);
        Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => '2026-10-15', 'amount' => 100, 'description' => 'Mercado', 'bank_account_id' => $this->btg->id]);

        $own = app(OwnFinancialService::class);
        $oct = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59')];
        $this->assertEquals(100.0, $own->totals(...$oct, regime: 'accrual')['expense']);
        $this->assertEquals(100.0, app(OwnFinancialService::class)->totals(...$oct, regime: 'cash')['expense']);

        // caixa: pagar a fatura que tem a compra do bem não vira despesa
        $invoice = CreditCardInvoice::first();
        app(InvoicePaymentService::class)->pay($invoice, $this->btg, 1000, '2026-11-05');
        $nov = [Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30 23:59:59')];
        $this->assertEquals(0.0, app(OwnFinancialService::class)->totals(...$nov, regime: 'cash')['expense']);

        $report = app(CashflowReportService::class)->realized(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [], 'accrual');
        $this->assertArrayNotHasKey('Aquisição de bem', $report['by_category']->all());
        $this->assertEquals(100.0, $report['own_expense']);
    }

    public function test_changing_category_makes_it_count_as_expense(): void
    {
        $other = Category::create(['name' => 'Acessórios', 'type' => 'expense', 'status' => true]);
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '500', 'method' => 'account', 'bank_account_id' => $this->btg->id, 'category_id' => $other->id]);
        $this->assertEquals(500.0, app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), 'accrual')['expense']);
    }

    public function test_card_payment_in_installments_goes_to_invoices(): void
    {
        $card = $this->card();
        $pay = $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-11-10', 'amount' => '55000', 'method' => 'card', 'credit_card_id' => $card->id, 'installments' => 3]);
        $tx = $pay->transactions()->orderBy('installment_number')->get();
        $this->assertCount(3, $tx);
        $this->assertEquals(55000.0, round((float) $tx->sum('amount'), 2));
        $this->assertSame([1, 2, 3], $tx->pluck('installment_number')->all());
        $this->assertTrue($tx->every(fn ($t) => $t->source_type === PropertyPayment::class && $t->credit_card_invoice_id && $t->bank_account_id === null));
        $this->assertSame(3, CreditCardInvoice::count());
        $this->assertEquals(55000.0, round((float) CreditCardInvoice::sum('total'), 2));
        $this->assertEqualsWithDelta(63410, $this->btg->fresh()->balance(), 0.001, 'cartão não mexe no saldo da conta');
    }

    public function test_planned_payment_touches_nothing_and_paguei_generates(): void
    {
        $count = Transaction::count();
        $planned = $this->service()->record($this->watch, ['status' => 'planned', 'amount' => '55000', 'method' => 'card']);
        $this->assertNull($planned->credit_card_id);
        $this->assertNull($planned->date);
        $this->assertSame($count, Transaction::count());
        $this->assertSame(0, CreditCardInvoice::count());
        $this->assertEquals(55000.0, $this->watch->fresh()->paymentSummary()['planned']);

        $card = $this->card();
        $paid = $this->service()->markPaid($planned, ['date' => '2026-11-12', 'amount' => '55000', 'method' => 'card', 'credit_card_id' => $card->id, 'installments' => 10]);
        $this->assertSame('paid', $paid->status);
        $this->assertCount(10, $paid->transactions);
        $this->assertEquals(0.0, $this->watch->fresh()->paymentSummary()['planned']);

        $this->expectException(\DomainException::class);
        $this->service()->markPaid($paid->fresh(), ['date' => '2026-11-12', 'amount' => '1', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
    }

    public function test_paid_requires_date_method_and_source(): void
    {
        foreach ([
            ['status' => 'paid', 'amount' => '10', 'method' => 'account', 'bank_account_id' => $this->btg->id],
            ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '10'],
            ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '10', 'method' => 'card'],
            ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '0', 'method' => 'account', 'bank_account_id' => $this->btg->id],
        ] as $bad) {
            try {
                $this->service()->record($this->watch, $bad);
                $this->fail('deveria recusar '.json_encode($bad));
            } catch (\DomainException) {
            }
        }
        $this->assertSame(0, PropertyPayment::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_deleting_payment_deletes_entries_and_recalculates_invoice(): void
    {
        $card = $this->card();
        $acc = $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
        $cc = $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-11-10', 'amount' => '3000', 'method' => 'card', 'credit_card_id' => $card->id, 'installments' => 2]);
        $this->service()->delete($acc);
        $this->service()->delete($cc);
        $this->assertSame(0, PropertyPayment::count());
        $this->assertSame(0, Transaction::count());
        $this->assertEquals(0.0, (float) CreditCardInvoice::sum('total'));
        $this->assertEqualsWithDelta(63410, $this->btg->fresh()->balance(), 0.001);
    }

    public function test_over_total_and_duplicate_warnings(): void
    {
        $this->assertNull($this->service()->exceedsTotal($this->watch, 70800));
        $this->assertEquals(200.0, $this->service()->exceedsTotal($this->watch, 71000));

        Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => '2026-10-31', 'amount' => 15800, 'description' => 'PIX ENVIADO RELOJOARIA', 'bank_account_id' => $this->btg->id, 'ofx_fitid' => 'X1']);
        $this->assertCount(1, $this->service()->possibleDuplicates($this->btg->id, 15800, '2026-10-30'));
        $this->assertCount(0, $this->service()->possibleDuplicates($this->btg->id, 15800, '2026-11-10'));

        $c = Volt::test('bens.index')->call('openPayments', $this->watch->id)->call('newPayment')
            ->set('payStatus', 'paid')->set('payDate', '2026-10-30')->set('payAmount', '15.800,00')->set('payMethod', 'account')->set('payAccount', (string) $this->btg->id)
            ->call('savePayment')->assertSee('talvez vindo do extrato importado');
        $this->assertSame(0, PropertyPayment::count(), 'só salva depois de confirmar');
        $c->call('savePayment', true)->assertHasNoErrors();
        $this->assertSame(1, PropertyPayment::count());

        $c->call('newPayment')->set('payStatus', 'planned')->set('payMethod', 'card')->set('payAmount', '60.000,00')->set('payDate', '')
            ->call('savePayment')->assertSee('passa do valor total da compra');
        $this->assertSame(1, PropertyPayment::count());
    }

    public function test_statement_import_flags_payment_as_possible_duplicate(): void
    {
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
        $rows = app(StatementImportAnalyzer::class)->analyze([['date' => '2026-10-30', 'amount' => -15800, 'description' => 'PIX ENVIADO RELOJOARIA']], 'bank', $this->btg->id);
        $this->assertSame('duplicate', $rows[0]['status']);
        $this->assertStringContainsString('pagamento de bem', $rows[0]['note']);
    }

    public function test_screen_flow_records_payment_planned_and_paguei(): void
    {
        $card = $this->card();
        $c = Volt::test('bens.index')->call('openPayments', $this->watch->id)
            ->assertSee('Valor total da compra')->assertSee('Nenhum pagamento registrado')
            ->call('newPayment')->assertSet('payAccount', (string) $this->btg->id)->assertSet('payCategory', (string) Category::assetPurchaseCategoryId())
            ->set('payDate', '2026-10-30')->set('payAmount', '15.800,00')->set('payNotes', 'Entrada')
            ->call('savePayment')->assertHasNoErrors()->assertSet('showPayForm', false);
        $this->assertSame('Bem: Relógio Rolex · Entrada', Transaction::first()->description);

        $c->call('newPayment')->assertSet('payAmount', '55000.00')->set('payStatus', 'planned')->set('payMethod', 'card')->set('payDate', '')
            ->call('savePayment')->assertHasNoErrors();
        $planned = PropertyPayment::where('status', 'planned')->first();
        $this->assertNull($planned->credit_card_id);
        $c->assertSee('Pago')->assertSee('R$ 15.800,00')->assertSee('R$ 55.000,00')->assertSee('Paguei');

        $c->call('markPaidForm', $planned->id)->set('payCard', '')->call('savePayment')->assertSee('Escolha o cartão');
        $c->set('payCard', (string) $card->id)->set('payInstallments', '5')->call('savePayment')->assertHasNoErrors();
        $this->assertSame(5, $planned->fresh()->transactions()->count());

        $c->call('deletePayment', $planned->id);
        $this->assertSame(1, Transaction::count());
    }

    public function test_cannot_delete_property_with_paid_payments_and_old_assets_unchanged(): void
    {
        $old = Property::create(['kind' => 'imovel', 'description' => 'Casa Antiga', 'acquisition_value' => 100, 'current_value' => 90, 'status' => 'active']);
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
        $html = Volt::test('bens.index')->call('delete', $this->watch->id)->html();
        $this->assertNotSoftDeleted($this->watch);
        $this->assertSame(2, substr_count($html, 'data-payment-bar'), 'só o relógio tem barra (celular + desktop); bem antigo continua igual');
        $this->assertSame(0, $old->payments()->count());
        Volt::test('bens.index')->call('delete', $old->id);
        $this->assertSoftDeleted($old);
    }

    public function test_transactions_list_shows_bem_link(): void
    {
        $this->service()->record($this->watch, ['status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account', 'bank_account_id' => $this->btg->id]);
        Volt::test('banking.transactions.index')->set('from', '2026-10-01')->set('to', '2026-10-31')
            ->assertSeeHtml('data-bem-link')->assertSee('Relógio Rolex')->assertSee('Pagamento de bem')
            ->assertSeeHtml(route('bens.index', ['bem' => $this->watch->id]));
    }
}

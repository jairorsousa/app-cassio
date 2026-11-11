<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\OfxImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvoiceManageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->user = User::factory()->create();
        $this->user->forceFill(['is_admin' => true])->save();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = []): CreditCard
    {
        return CreditCard::create(array_merge([
            'name' => 'BB 1126', 'last_four' => '1126', 'limit' => 1000,
            'closing_day' => 8, 'due_day' => 20,
        ], $attrs));
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['name' => 'Banco do Brasil', 'initial_balance' => 5000])->fresh();
    }

    private function category(): Category
    {
        return Category::forceCreate(['name' => 'Supermercado', 'type' => 'expense', 'status' => true]);
    }

    private function purchase(CreditCard $card, string $date, float $amount, string $desc, ?CreditCardInvoice $invoice = null, array $extra = []): Transaction
    {
        $invoice ??= app(InvoiceService::class)->findOrCreateForPurchase($card, Carbon::parse($date));
        $tx = Transaction::create(array_merge([
            'type' => 'expense', 'date' => $date, 'amount' => number_format($amount, 2, '.', ''),
            'description' => $desc, 'status' => 'settled',
            'credit_card_id' => $card->id, 'credit_card_invoice_id' => $invoice->id,
        ], $extra));
        app(InvoiceService::class)->recalculateTotal($invoice->fresh());

        return $tx->fresh();
    }

    public function test_ciclo_fecha_dia_8_define_a_fatura(): void
    {
        $svc = app(InvoiceService::class);
        $card = $this->card();
        $this->assertSame('2026-04', $svc->referenceMonthForPurchase($card, Carbon::parse('2026-04-08')));
        $this->assertSame('2026-05', $svc->referenceMonthForPurchase($card, Carbon::parse('2026-04-09')));
        $this->assertSame('2026-11', $svc->referenceMonthForPurchase($card, Carbon::parse('2026-10-22')));
    }

    public function test_importacao_automatica_coloca_cada_compra_na_fatura_do_ciclo(): void
    {
        $card = $this->card();
        $csv = "Data;Descrição;Valor\n08/04/2026;BARATAO;-949,53\n09/04/2026;PIZZA;-100,00\n22/10/2026;MATEUS;-17,98\n";
        $file = UploadedFile::fake()->createWithContent('OUROCARD_Mai.csv', $csv);
        Volt::test('banking.transactions.statement-import')
            ->set('target', 'card')->set('cardId', $card->id)->set('invoiceMode', 'auto')
            ->set('file', $file)->call('analyzeFile')->assertHasNoErrors();

        $c = Volt::test('banking.transactions.statement-import')
            ->set('target', 'card')->set('cardId', $card->id)->set('invoiceMode', 'auto')
            ->set('file', $file)->call('analyzeFile');
        $rows = $c->get('rows');
        $this->assertSame('2026-04', $rows[0]['suggested_invoice_month']);
        $this->assertSame('2026-05', $rows[1]['suggested_invoice_month']);
        $this->assertSame('2026-11', $rows[2]['suggested_invoice_month']);
        $this->assertFalse($rows[0]['invoice_mismatch']);
        $c->call('confirmImport')->assertHasNoErrors();

        $this->assertEquals(949.53, (float) CreditCardInvoice::where('reference_month', '2026-04')->first()->total);
        $this->assertEquals(100.00, (float) CreditCardInvoice::where('reference_month', '2026-05')->first()->total);
        $this->assertEquals(17.98, (float) CreditCardInvoice::where('reference_month', '2026-11')->first()->total);
        $this->assertTrue(Transaction::where('description', 'BARATAO')->whereNotNull('import_batch_id')->exists());
    }

    public function test_importacao_fixa_avisa_quando_a_data_nao_combina_e_ainda_grava_na_escolhida(): void
    {
        $card = $this->card();
        $csv = "Data;Descrição;Valor\n08/04/2026;BARATAO;-949,53\n22/10/2026;MATEUS;-17,98\n";
        $file = UploadedFile::fake()->createWithContent('fat.csv', $csv);
        $c = Volt::test('banking.transactions.statement-import')
            ->set('target', 'card')->set('cardId', $card->id)
            ->set('invoiceMode', 'fixed')->set('invoiceMonth', '2026-11')
            ->set('file', $file)->call('analyzeFile')->assertHasNoErrors();
        $rows = $c->get('rows');
        $this->assertTrue($rows[0]['invoice_mismatch']);
        $this->assertSame('2026-11', $rows[0]['invoice_month']);
        $this->assertFalse($rows[1]['invoice_mismatch']);
        $c->assertSee('compra(s) com data que não combina');
        $c->call('confirmImport')->assertHasNoErrors();
        $nov = CreditCardInvoice::where('reference_month', '2026-11')->first();
        $this->assertEquals(967.51, (float) $nov->total);
        $this->assertNull(CreditCardInvoice::where('reference_month', '2026-04')->first());
    }

    public function test_editar_mover_e_excluir_compra_recalcula_totais_sem_mexer_no_saldo_da_conta(): void
    {
        $card = $this->card();
        $account = $this->account();
        $before = $account->balance();
        $cat = $this->category();
        $nov = app(InvoiceService::class)->findOrCreateForReference($card, Carbon::parse('2026-11-01'));
        $a = $this->purchase($card, '2026-04-08', 949.53, 'BARATAO', $nov);
        $b = $this->purchase($card, '2026-10-22', 17.98, 'MATEUS', $nov);
        $this->assertEquals(967.51, (float) $nov->fresh()->total);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->assertSee('Gerenciar')->assertSee('Desfazer importação')
            ->call('toggle', $nov->id)->assertSee('BARATAO')
            ->call('startEditPurchase', $a->id)
            ->assertSee('Editar compra')
            ->set('edit_date', '2026-04-08')->set('edit_description', 'BARATAO 4 BOCAS')
            ->set('edit_amount', '949,53')->set('edit_category_id', $cat->id)
            ->set('edit_invoice_month', '2026-04')
            ->call('savePurchase')->assertHasNoErrors();

        $this->assertEquals(17.98, (float) $nov->fresh()->total);
        $abr = CreditCardInvoice::where('reference_month', '2026-04')->first();
        $this->assertEquals(949.53, (float) $abr->total);
        $this->assertSame('BARATAO 4 BOCAS', $a->fresh()->description);
        $this->assertEquals($cat->id, $a->fresh()->category_id);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->set('selectedIds', [$b->id])->set('move_to_month', '2026-11')
            ->call('moveSelected')->assertHasNoErrors();
        $this->assertEquals(17.98, (float) $nov->fresh()->total);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('deletePurchase', $b->id)->assertHasNoErrors();
        $this->assertEquals(0.0, (float) $nov->fresh()->total);
        $this->assertSoftDeleted('transactions', ['id' => $b->id]);
        $this->assertEquals($before, $account->fresh()->balance());
    }

    public function test_lote_move_varias_compras(): void
    {
        $card = $this->card();
        $nov = app(InvoiceService::class)->findOrCreateForReference($card, Carbon::parse('2026-11-01'));
        $a = $this->purchase($card, '2026-09-26', 35, 'APPLE 1', $nov);
        $b = $this->purchase($card, '2026-09-28', 5, 'APPLE 2', $nov);
        Volt::test('banking.cards.invoices', ['card' => $card])
            ->set('selectedIds', [$a->id, $b->id])->set('move_to_month', '2026-10')
            ->call('moveSelected')->assertHasNoErrors();
        $out = CreditCardInvoice::where('reference_month', '2026-10')->first();
        $this->assertEquals(40.0, (float) $out->total);
        $this->assertEquals(0.0, (float) $nov->fresh()->total);
    }

    public function test_desfazer_importacao_remove_so_o_lote_e_mantem_lancamento_manual(): void
    {
        $card = $this->card();
        $svc = app(OfxImportService::class);
        $svc->importCard($card, null, [
            ['fitid' => 'stmt:a', 'date' => '2026-04-08', 'amount' => '10.00', 'type' => 'expense', 'description' => 'IMP 1', 'notes' => 'Importado de extrato (PDF (texto))', 'card_kind' => 'purchase', 'category_id' => null, 'additional_card_id' => null],
            ['fitid' => 'stmt:b', 'date' => '2026-04-07', 'amount' => '20.00', 'type' => 'expense', 'description' => 'IMP 2', 'notes' => 'Importado de extrato (PDF (texto))', 'card_kind' => 'purchase', 'category_id' => null, 'additional_card_id' => null],
        ], ['filename' => 'OUROCARD_Mai.csv', 'source_label' => 'PDF (texto)', 'invoice_mode' => 'auto']);
        $manual = $this->purchase($card, '2026-08-20', 580.55, 'LATAM DCP ND* (1/3)');
        $this->assertSame(3, Transaction::where('credit_card_id', $card->id)->count());

        $page = Volt::test('banking.cards.invoices', ['card' => $card])->call('openUndo');
        $imports = $page->get('imports') ?? $page->viewData('imports');
        // viewData may not expose with() keys; call service
        $list = app(\App\Domains\Banking\Services\InvoicePurchaseService::class)->listImports($card);
        $this->assertCount(1, $list);
        $this->assertSame(2, $list[0]['items_count']);
        $page->set('undoKey', $list[0]['key'])->call('confirmUndo')->assertHasNoErrors();

        $this->assertSame(1, Transaction::where('credit_card_id', $card->id)->count());
        $this->assertTrue(Transaction::where('id', $manual->id)->exists());
        $this->assertEquals(580.55, (float) CreditCardInvoice::where('reference_month', '2026-09')->first()->total);
    }

    public function test_reabrir_editar_datas_e_excluir_fatura_vazia_ou_com_pagamento(): void
    {
        $card = $this->card();
        $account = $this->account();
        $inv = app(InvoiceService::class)->findOrCreateForReference($card, Carbon::parse('2026-10-01'));
        $p = $this->purchase($card, '2026-09-20', 50, 'Compra', $inv);
        app(InvoiceService::class)->closeInvoice($inv->fresh());
        $this->assertSame('closed', $inv->fresh()->status);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('reopen', $inv->id)->assertHasNoErrors();
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertNull($inv->fresh()->closed_at);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('startEditInvoice', $inv->id)
            ->set('invoice_closing_date', '2026-10-10')
            ->set('invoice_due_date', '2026-10-25')
            ->call('saveInvoice')->assertHasNoErrors();
        $this->assertSame('2026-10-10', $inv->fresh()->closing_date->format('Y-m-d'));
        $this->assertSame('2026-10-25', $inv->fresh()->due_date->format('Y-m-d'));

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('deleteInvoice', $inv->id);
        $this->assertDatabaseHas('credit_card_invoices', ['id' => $inv->id]);

        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('deletePurchase', $p->id);
        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('deleteInvoice', $inv->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('credit_card_invoices', ['id' => $inv->id]);

        $paid = app(InvoiceService::class)->findOrCreateForReference($card, Carbon::parse('2026-11-01'));
        $buy = $this->purchase($card, '2026-10-20', 30, 'Outra', $paid);
        app(InvoiceService::class)->closeInvoice($paid->fresh());
        app(InvoicePaymentService::class)->pay($paid->fresh(), $account, 30, '2026-11-11');
        $before = $account->fresh()->balance();
        Volt::test('banking.cards.invoices', ['card' => $card])
            ->call('deleteInvoice', $paid->id)
            ->assertSee('pagamento registrado');
        $this->assertDatabaseHas('credit_card_invoices', ['id' => $paid->id]);
        $this->assertEquals($before, $account->fresh()->balance());
        $this->assertTrue(Transaction::where('id', $buy->id)->exists());
    }

    public function test_pagina_de_faturas_mostra_gerenciar_no_html(): void
    {
        $card = $this->card();
        $this->purchase($card, '2026-10-22', 5, 'APPLE');
        $html = Volt::test('banking.cards.invoices', ['card' => $card])->html();
        $this->assertStringContainsString('data-invoice-manage', $html);
        $this->assertStringContainsString('Desfazer importação', $html);
        $this->assertStringContainsString('Editar vencimento e fechamento', $html);
    }
}

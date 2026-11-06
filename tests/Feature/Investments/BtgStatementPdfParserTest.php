<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Services\Statements\BrokerStatementImportService;
use App\Domains\Investments\Services\Statements\BtgStatementPdfParser;
use App\Domains\Investments\Services\Statements\PdfTextExtractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Layout BTG com dados anonimizados/sintéticos (nenhum valor do extrato real). */
class BtgStatementPdfParserTest extends TestCase
{
    use RefreshDatabase;

    private function text(): string
    {
        return file_get_contents(base_path('tests/fixtures/statements/btg_layout_anonimizado.txt'));
    }

    private function fakePdf(string $text): void
    {
        $this->app->instance(PdfTextExtractor::class, new class($text) extends PdfTextExtractor {
            public function __construct(private string $t) {}

            public function extract(string $path): array
            {
                return ['status' => 'text_extracted', 'text' => $this->t];
            }
        });
    }

    private function stage(string $text)
    {
        Storage::fake('local');
        $this->fakePdf($text);

        return app(BrokerStatementImportService::class)->stage(UploadedFile::fake()->createWithContent('btg.pdf', '%PDF-1.4'), 'BTG Pactual', User::factory()->create()->id);
    }

    public function test_classifies_btg_descriptions(): void
    {
        $c = fn ($d) => BtgStatementPdfParser::classify($d);
        $this->assertSame(['lending_fee', 'AAAA3'], $c('TAXA REMUNERAÇÃO - BTC AAAA3'));
        $this->assertSame(['lending_tax', 'AAAA3'], $c('IR - BTC AAAA3'));
        $this->assertSame(['fee', null], $c('LIQ BOLSA (Corretagem BTC Aluguel)'));
        $this->assertSame(['lending_settlement', null], $c('LIQ BOLSA (ALUGUEL)'));
        $this->assertSame(['jcp', 'AAAA3'], $c('JUROS S/ CAPITAL s/ EMPRESA PN N1 - AAAA3'));
        $this->assertSame(['jcp', 'AAAA3'], $c('JUROS S/ CAPITAL - À VISTA s/ EMPRESA PN N1 - AAAA3'));
        $this->assertSame(['lending_reimbursement', 'AAAA3'], $c('JUROS S/ CAPITAL - ALUGUEL s/ EMPRESA PN N1 - AAAA3'));
        $this->assertSame(['lending_reimbursement', 'CCCC3'], $c('DIVIDENDOS - ALUGUEL DE AÇÕES s/ X ON NM - CCCC3'));
        $this->assertSame(['yield', 'DDDD3'], $c('RENDIMENTOS s/ X ON NM - DDDD3'));
        $this->assertSame(['fii_income', 'FFFF11'], $c('RENDIMENTOS - À VISTA s/ F CI - FFFF11'));
        $this->assertSame(['trade_settlement', null], $c('LIQ BOLSA (Operacoes)- Pregão:02/01/2026'));
        $this->assertSame(['transfer', null], $c('TED ENVIADA - X'));
        $this->assertSame(['cash_yield', null], $c('Rendimento Disponível - Saldo Remunerado'));
        $this->assertSame(['fraction', 'AAAA3'], $c('LEILÃO DE FRAÇÕES - À VISTA s/ X PN N1 - AAAA3'));
        $this->assertSame(['lending_reimbursement', 'MMMM3'], $c('LEILÃO DE FRAÇÕES - ALUGUEL s/ X ON - MMMM3'));
        $this->assertSame(['subscription_right', 'PPPP1'], $c('SU-PPPP1 - LIQ FIN DIREITOS SUB BTC'));
        $this->assertSame(['tax', null], $c('IRRF - LEI 11.033/04 - AÇÃO (VENDAS)'));
        $this->assertSame(['fee', 'AAAA3'], $c('TAXA EMOLUMENTOS - BTC AAAA3'));
    }

    public function test_parse_reconciles_by_running_balance_and_flags_printed_final(): void
    {
        $this->assertTrue(BtgStatementPdfParser::detect($this->text()));
        $p = app(BtgStatementPdfParser::class)->parse($this->text());
        $r = $p['reconciliation'];
        $this->assertCount(17, $p['rows']);
        $this->assertSame(1000.0, $r['opening']);
        $this->assertSame(76.5, $r['credits']);
        $this->assertSame(609.5, $r['debits']);
        $this->assertSame(467.0, $r['computed_final']);
        $this->assertSame(467.0, $r['last_running_balance']);
        $this->assertSame(0, $r['line_mismatches']);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['printed_final_differs']); // Saldo Final impresso 466,30 exclui o rendimento disponível
        // débito/crédito pelo saldo, independente da coluna
        $this->assertSame(-0.5, $p['rows'][0]['amount']);
        $this->assertSame(10.0, $p['rows'][1]['amount']);
    }

    public function test_broken_running_balance_is_flagged_and_blocks_confirm(): void
    {
        $bad = str_replace('1.028,00', '1.029,00', $this->text());
        $p = app(BtgStatementPdfParser::class)->parse($bad);
        $this->assertFalse($p['reconciliation']['ok']);
        $this->assertSame(2, $p['reconciliation']['line_mismatches']);
        $imp = $this->stage($bad);
        $this->expectExceptionMessage('não reconcilia');
        app(BrokerStatementImportService::class)->confirm($imp, $imp->created_by);
    }

    public function test_stage_links_ir_to_fee_and_routes_kinds_without_writing_events(): void
    {
        $tx = Transaction::count();
        $imp = $this->stage($this->text());
        $this->assertSame('draft', $imp->status);
        $this->assertSame('btg_conta_investimento', $imp->mapping['layout']);
        $rows = $imp->rows()->get();
        $fee = $rows->firstWhere('kind', 'lending_fee');
        $this->assertEquals(10, $fee->gross_amount);
        $this->assertEquals(1.5, $fee->withholding_tax);
        $this->assertEquals(8.5, $fee->net_amount);
        $this->assertSame('ready', $fee->status);
        $this->assertSame('linked', $rows->firstWhere('kind', 'lending_tax')->status);
        foreach (['fee', 'lending_settlement', 'trade_settlement', 'transfer', 'cash_yield', 'tax', 'fraction'] as $k) {
            $this->assertSame('pending_manual', $rows->firstWhere('kind', $k)->status, $k);
        }
        $noTicker = $rows->first(fn ($r) => $r->kind === 'jcp' && $r->ticker === null);
        $this->assertSame('error', $noTicker->status);
        $this->assertEquals(['error' => 1, 'linked' => 1, 'pending_manual' => 9, 'ready' => 6], $rows->countBy('status')->sortKeys()->all());
        $this->assertSame(6, $rows->where('status', 'ready')->count());
        $this->assertSame(0, AssetIncomeEvent::count());
        $this->assertSame($tx, Transaction::count());
    }

    public function test_existing_b3_event_becomes_duplicate_candidate_and_fixing_ticker_allows_import(): void
    {
        $b3 = AssetIncomeEvent::create(['ticker' => 'BBBB3', 'payment_date' => '2026-01-03', 'type' => 'dividend', 'net_amount' => 30, 'source' => 'b3_midas', 'source_key' => 'b3inc:z:1', 'broker_name' => 'BTG Pactual']);
        $imp = $this->stage($this->text());
        $svc = app(BrokerStatementImportService::class);
        $dup = $imp->rows()->where('kind', 'dividend')->first();
        $this->assertSame('duplicate_candidate', $dup->status);
        $this->assertSame($b3->id, $dup->duplicate_of_event_id);
        $svc->updateRow($imp->rows()->where('kind', 'jcp')->whereNull('ticker')->first(), ['ticker' => 'EEEE3']);
        $r = $svc->confirm($imp->fresh(), $imp->created_by);
        $this->assertSame(6, $r['imported']); // 5 prontos + JCP corrigido; o dividendo duplicado fica de fora
        $this->assertSame(1, AssetIncomeEvent::where('ticker', 'BBBB3')->count());
        $this->assertSame(7, AssetIncomeEvent::count());
        $this->assertSame('yield', AssetIncomeEvent::where('ticker', 'DDDD3')->value('type'));
    }

    public function test_preview_command_writes_nothing(): void
    {
        $this->fakePdf($this->text());
        $f = tempnam(sys_get_temp_dir(), 'btg').'.pdf';
        file_put_contents($f, '%PDF-1.4');
        $this->artisan('investments:statement-preview', ['file' => $f])->expectsOutputToContain('nada foi gravado')->assertSuccessful();
        $this->assertSame(0, \App\Domains\Investments\Models\BrokerStatementImport::count());
        $this->assertSame(0, AssetIncomeEvent::count());
    }

    public function test_ticker_with_digit_and_inference_from_issuer_name(): void
    {
        $this->assertSame(['lending_fee', 'B3SA3'], BtgStatementPdfParser::classify('TAXA REMUNERAÇÃO - BTC B3SA3'));
        $this->assertSame(BtgStatementPdfParser::issuerKey('JUROS S/ CAPITAL s/ LOJAS RENNER ON NM - LLLL3'), BtgStatementPdfParser::issuerKey('JUROS S/ CAPITAL - ALUGUEL s/ LOJAS RENNERON'));
        $text = "Movimentação - Conta Investimento\nBanco: 208 BTGPACTUAL\n"
            ."01/01/2026   Saldo Inicial                                                   100,00\n"
            ."02/01/2026   JUROS S/ CAPITAL s/ LOJAS RENNER ON NM - LLLL3          1,00      101,00\n"
            ."03/01/2026   JUROS S/ CAPITAL - ALUGUEL s/ LOJAS RENNERON            2,00      103,00\n"
            ."04/01/2026   DIVIDENDOS - À VISTA s/ CAIXA                          3,00      106,00\n"
            ."05/01/2026   DIVIDENDOS s/ CAIXA SEGURI ON NM - CCCC3                 4,00      110,00\n"
            ."06/01/2026   DIVIDENDOS s/ CAIXA OUTRA ON NM - CXXX3                 5,00      115,00\n";
        $rows = app(BtgStatementPdfParser::class)->parse($text)['rows'];
        $this->assertSame('LLLL3', $rows[1]['ticker']);
        $this->assertSame('lending_reimbursement', $rows[1]['kind']);
        $this->assertStringContainsString('inferido', implode(' ', $rows[1]['messages']));
        $this->assertNull($rows[2]['ticker']); // prefixo "CAIXA" ambíguo entre dois emissores
        $this->assertStringContainsString('ambíguo', implode(' ', $rows[2]['messages']));
    }
}

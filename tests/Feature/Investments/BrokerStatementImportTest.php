<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\BrokerStatementImport;
use App\Domains\Investments\Models\BrokerStatementRow;
use App\Domains\Investments\Services\Statements\BrokerStatementImportService;
use App\Domains\Investments\Services\Statements\BrokerStatementParser;
use App\Domains\Investments\Services\Statements\PdfTextExtractor;
use App\Domains\Investments\Services\Statements\StatementAiAssistant;
use App\Domains\Investments\Services\Statements\TabularFileReader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Somente dados sintéticos/anonimizados (nenhum arquivo real de corretora ou do Midas). */
class BrokerStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const CSV = "Extrato de proventos - Corretora Exemplo\n"
        ."Data;Ativo;Evento;Qtd;Valor Bruto;IR;Valor Líquido;Nº\n"
        ."10/03/2026;AAAA3 - EMPRESA A;Dividendo;100;12,00;0,00;12,00;A1\n"
        ."15/03/2026;AAAA3;Juros sobre capital próprio;100;10,00;1,50;8,50;A2\n"
        ."20/03/2026;FFFF11;Rendimento;10;8,10;;8,10;A3\n"
        ."21/03/2026;CCCC3;Reembolso aluguel;30;1,50;;1,50;A4\n"
        ."22/03/2026;CCCC3;Aluguel BTC doador;30;0,90;0,14;0,76;A5\n"
        ."23/03/2026;AAAA3;Compra;10;;;-120,00;A6\n"
        ."24/03/2026;;Transferência de custódia;;;;0,00;A7\n";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): BrokerStatementImportService
    {
        return app(BrokerStatementImportService::class);
    }

    private function stageCsv(string $csv = self::CSV): BrokerStatementImport
    {
        return $this->svc()->stage(UploadedFile::fake()->createWithContent('extrato.csv', $csv), 'Corretora Exemplo', $this->user->id);
    }

    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $strings = [];
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cells) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $v) {
                $ref = chr(65 + $c).($r + 1);
                if (is_numeric($v)) {
                    $sheet .= "<c r=\"$ref\"><v>$v</v></c>";
                } else {
                    $strings[] = $v;
                    $sheet .= "<c r=\"$ref\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $ss = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s).'</t></si>', $strings)).'</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ss);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }

    public function test_parser_normalizes_numbers_dates_kinds(): void
    {
        $this->assertSame(1234.56, BrokerStatementParser::number('R$ 1.234,56'));
        $this->assertSame(1234.56, BrokerStatementParser::number('1,234.56'));
        $this->assertSame(-120.0, BrokerStatementParser::number('(120,00)'));
        $this->assertNull(BrokerStatementParser::number('-'));
        $this->assertSame('2026-03-10', BrokerStatementParser::date('10/03/2026'));
        $this->assertSame('2026-03-10', BrokerStatementParser::date('46091'));
        $this->assertNull(BrokerStatementParser::date('31/02/2026'));
        $this->assertSame('lending_reimbursement', BrokerStatementParser::kind('Reembolso de aluguel'));
        $this->assertSame('lending_fee', BrokerStatementParser::kind('Aluguel BTC'));
        $this->assertSame('jcp', BrokerStatementParser::kind('JUROS SOBRE CAPITAL'));
        $this->assertSame('fii_income', BrokerStatementParser::kind('Rendimento'));
        $this->assertSame('sell', BrokerStatementParser::kind('Venda'));
        $this->assertSame('unknown', BrokerStatementParser::kind(''));
        $this->assertSame('BBBB11', BrokerStatementParser::ticker('bbbb11 - fundo'));
    }

    public function test_csv_preview_maps_columns_and_writes_no_financial_data(): void
    {
        $tx = Transaction::count();
        $imp = $this->stageCsv();
        $this->assertSame('draft', $imp->status);
        $this->assertSame(['date' => 'Data', 'ticker' => 'Ativo', 'type' => 'Evento', 'quantity' => 'Qtd', 'gross' => 'Valor Bruto', 'tax' => 'IR', 'net' => 'Valor Líquido', 'external_id' => 'Nº'], $imp->mapping);
        $s = $imp->summary;
        $this->assertSame(7, $s['rows']);
        $this->assertSame(5, $s['by_status']['ready']);
        $this->assertSame(2, $s['by_status']['pending_manual']);
        $this->assertEquals(30.86, $s['ready_net']);
        $jcp = $imp->rows->firstWhere('kind', 'jcp');
        $this->assertEquals(10, $jcp->gross_amount);
        $this->assertEquals(1.5, $jcp->withholding_tax);
        $this->assertEquals(8.5, $jcp->net_amount);
        $this->assertSame(0, AssetIncomeEvent::count());
        $this->assertSame($tx, Transaction::count());
        $this->assertTrue(Storage::disk('local')->exists($imp->file_path));
    }

    public function test_xlsx_reader_reads_shared_strings_and_numbers(): void
    {
        $path = $this->xlsx([['Relatório'], ['Data', 'Ticker', 'Tipo', 'Valor Líquido'], ['10/03/2026', 'AAAA3', 'Dividendo', '12.5'], [46091, 'BBBB3', 'JCP', '3']]);
        $t = app(TabularFileReader::class)->read($path, 'xlsx');
        $this->assertSame(['Data', 'Ticker', 'Tipo', 'Valor Líquido'], $t['headers']);
        $this->assertSame(['46091', 'BBBB3', 'JCP', '3'], $t['rows'][1]);
        $imp = $this->svc()->stage(new UploadedFile($path, 'extrato.xlsx', null, null, true), 'Corretora X', $this->user->id);
        $this->assertSame(2, $imp->summary['by_status']['ready']);
        $this->assertSame('2026-03-10', $imp->rows[1]->date->toDateString());
    }

    public function test_confirm_imports_only_income_idempotently_without_financeiro(): void
    {
        $tx = Transaction::count();
        $r = $this->svc()->confirm($this->stageCsv(), $this->user->id);
        $this->assertSame(['imported' => 5, 'skipped' => 0, 'pending_manual' => 2], $r);
        $this->assertSame(5, AssetIncomeEvent::where('source', 'broker_statement')->count());
        $this->assertSame($tx, Transaction::count());
        $fee = AssetIncomeEvent::where('type', 'lending_fee')->first();
        $this->assertTrue($fee->is_lending);
        $this->assertSame('A5', $fee->lending_contract_ref);
        $this->assertSame('Corretora Exemplo', $fee->broker_name);
        $imp = BrokerStatementImport::first();
        $this->assertSame('confirmed', $imp->status);
        $this->assertNull($imp->file_path);
        Storage::disk('local')->assertDirectoryEmpty(BrokerStatementImportService::DIR);

        $again = $this->stageCsv();
        $this->assertSame(5, $again->summary['by_status']['duplicate']);
        $this->assertSame(['imported' => 0, 'skipped' => 5, 'pending_manual' => 2], $this->svc()->confirm($again, $this->user->id));
        $this->assertSame(5, AssetIncomeEvent::count());
    }

    public function test_cross_source_duplicate_requires_human_decision_and_keeps_existing(): void
    {
        $b3 = AssetIncomeEvent::create(['ticker' => 'AAAA3', 'payment_date' => '2026-03-11', 'type' => 'dividend', 'net_amount' => 12.00, 'source' => 'b3_midas', 'source_key' => 'b3inc:x:1']);
        $imp = $this->stageCsv();
        $row = $imp->rows->firstWhere('kind', 'dividend');
        $this->assertSame('duplicate_candidate', $row->status);
        $this->assertSame($b3->id, $row->duplicate_of_event_id);
        $this->svc()->confirm($imp, $this->user->id);
        $this->assertSame(1, AssetIncomeEvent::where('ticker', 'AAAA3')->where('type', 'dividend')->count());
        $this->assertNotNull($b3->fresh());

        $imp2 = $this->stageCsv(str_replace('A1', 'A1b', self::CSV));
        $row2 = $imp2->rows->firstWhere('kind', 'dividend');
        $this->svc()->updateRow($row2, ['force_import' => true]);
        $this->assertSame('ready', $row2->fresh()->status);
    }

    public function test_errors_block_confirm_until_fixed_or_excluded(): void
    {
        $imp = $this->stageCsv("Data;Ativo;Evento;Valor Líquido\n31/02/2026;AAAA3;Dividendo;1,00\n10/03/2026;AAAA3;Bonificação;1,00\n");
        $this->assertSame(2, $imp->summary['by_status']['error']);
        try {
            $this->svc()->confirm($imp, $this->user->id);
            $this->fail('should block');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('erro', $e->getMessage());
        }
        foreach ($imp->rows as $row) {
            $this->svc()->updateRow($row, ['excluded' => true]);
        }
        $this->assertSame(['imported' => 0, 'skipped' => 0, 'pending_manual' => 0], $this->svc()->confirm($imp->fresh(), $this->user->id));
    }

    public function test_changed_file_invalidates_preview(): void
    {
        $imp = $this->stageCsv();
        Storage::disk('local')->put($imp->file_path, self::CSV."25/03/2026;AAAA3;Dividendo;1;9;;9;Z\n");
        $this->expectExceptionMessage('mudou depois da prévia');
        $this->svc()->confirm($imp, $this->user->id);
    }

    public function test_remap_changes_rows(): void
    {
        $imp = $this->stageCsv();
        $imp = $this->svc()->remap($imp, ['date' => 'Data', 'ticker' => 'Ativo', 'type' => 'Evento', 'net' => 'Valor Bruto', 'bogus' => 'X', 'tax' => 'NaoExiste']);
        $this->assertArrayNotHasKey('bogus', $imp->mapping);
        $this->assertArrayNotHasKey('tax', $imp->mapping);
        $this->assertEquals(10, $imp->rows->firstWhere('kind', 'jcp')->net_amount);
    }

    public function test_pdf_without_text_or_extractor_needs_review_and_imports_nothing(): void
    {
        $this->app->instance(PdfTextExtractor::class, new class extends PdfTextExtractor {
            protected function binary(): ?string
            {
                return null;
            }
        });
        $imp = $this->svc()->stage(UploadedFile::fake()->createWithContent('extrato.pdf', "%PDF-1.4\n%fake scanned\n"), 'Corretora X', $this->user->id);
        $this->assertSame('needs_review', $imp->status);
        $this->assertSame('extractor_unavailable', $imp->text_status);
        $this->assertSame(0, $imp->rows()->count());
        $this->assertSame(['imported' => 0, 'skipped' => 0, 'pending_manual' => 0], $this->svc()->confirm($imp, $this->user->id));

        $bad = (new PdfTextExtractor)->extract(tap(tempnam(sys_get_temp_dir(), 'p'), fn ($p) => file_put_contents($p, 'not a pdf')));
        $this->assertSame('invalid', $bad['status']);
        $scanned = new class extends PdfTextExtractor {
            protected function binary(): ?string
            {
                return '/bin/true';
            }
        };
        $p = tempnam(sys_get_temp_dir(), 'p');
        file_put_contents($p, "%PDF-1.4\n");
        $this->assertSame('no_text', $scanned->extract($p)['status']);
    }

    public function test_cleanup_expires_drafts_and_deletes_files(): void
    {
        $imp = $this->stageCsv();
        $path = $imp->file_path;
        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, $this->svc()->cleanup());
        $this->assertSame('expired', $imp->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, BrokerStatementRow::count());
        $this->expectExceptionMessage('expirado');
        $this->svc()->remap($imp->fresh(), []);
    }

    public function test_ai_disabled_sends_nothing_and_enabled_only_suggests(): void
    {
        Http::fake();
        $ai = app(StatementAiAssistant::class);
        $this->assertFalse($ai->enabled());
        $this->assertNull($ai->suggestMapping(['Data'], [['x']]));
        Http::assertNothingSent();

        config(['services.statement_ai' => ['provider' => 'teste', 'url' => 'https://ai.example/v1/chat/completions', 'key' => 'k', 'model' => 'm']]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['ai.example/*' => Http::response(['choices' => [['message' => ['content' => '{"mapping":{"date":"Data","net":"Valor Líquido","x":"Y","ticker":"Inexistente"},"notes":"ok"}']]]])]);
        $imp = $this->stageCsv();
        Volt::test('investments.statements.index')->set('mapping', [])->call('askAi')->assertSee('Sugestão da IA carregada')->assertSet('mapping', ['date' => 'Data', 'net' => 'Valor Líquido']);
        Http::assertSent(fn ($r) => count($r['messages']) === 1 && substr_count($r['messages'][0]['content'], 'Dividendo') <= 5);
        $this->assertSame($imp->mapping, $imp->fresh()->mapping); // sugestão não aplicada sozinha
        $this->assertSame(0, AssetIncomeEvent::count());
    }

    public function test_page_flow_upload_edit_confirm(): void
    {
        $c = Volt::test('investments.statements.index')->set('broker', 'Corretora Exemplo')
            ->set('file', UploadedFile::fake()->createWithContent('extrato.csv', self::CSV))
            ->call('upload')->assertSee('Prévia criada')->assertSee('Registrar manualmente')->assertSee('Confirmar importação')->assertSee('IA não configurada');
        $this->assertSame(0, AssetIncomeEvent::count());
        $row = BrokerStatementRow::where('kind', 'fii_income')->first();
        $c->call('setRow', $row->id, 'excluded', 1);
        $this->assertSame('excluded', $row->fresh()->status);
        $c->call('confirmImport')->assertSee('Importação confirmada: 4 provento');
        $this->assertSame(4, AssetIncomeEvent::count());
        $this->get('/investments/extratos')->assertOk();
        Volt::test('investments.statements.index')->set('broker', '')->call('upload')->assertHasErrors(['broker', 'file']);
    }
}

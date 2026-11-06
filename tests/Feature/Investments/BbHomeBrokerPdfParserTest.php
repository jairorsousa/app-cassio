<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\BrokerStatementImport;
use App\Domains\Investments\Services\Statements\BbHomeBrokerPdfParser;
use App\Domains\Investments\Services\Statements\BrokerStatementImportService;
use App\Domains\Investments\Services\Statements\PdfTextExtractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Layout BB Home Broker anonimizado (tickers e valores fictícios). */
class BbHomeBrokerPdfParserTest extends TestCase
{
    use RefreshDatabase;

    private function text(): string
    {
        return file_get_contents(base_path('tests/fixtures/statements/bb_home_broker_anonimizado.txt'));
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

    public function test_parses_rows_and_checks_repeated_page_total_once(): void
    {
        $this->assertTrue(BbHomeBrokerPdfParser::detect($this->text()));
        $p = app(BbHomeBrokerPdfParser::class)->parse($this->text());
        $r = $p['reconciliation'];
        $this->assertSame(4, $r['rows']);
        $this->assertSame(1120.9, $r['sum']);
        $this->assertSame(1120.9, $r['printed_total']); // repetido nas 2 páginas, não somado
        $this->assertTrue($r['ok']);
        $this->assertSame('2026-01-15', $p['rows'][0]['date']); // cronológico
        $this->assertSame('dividend', $p['rows'][0]['kind']);
        $this->assertSame('fii_income', $p['rows'][3]['kind']);
        $this->assertSame(1000.0, $p['rows'][2]['quantity']);
        $this->assertSame(1100.0, $p['rows'][2]['amount']);
    }

    public function test_total_mismatch_or_unparsed_line_is_flagged(): void
    {
        $p = app(BbHomeBrokerPdfParser::class)->parse(str_replace('7,90', '7,80', $this->text()));
        $this->assertFalse($p['reconciliation']['ok']);
        $broken = str_replace('QTDE:     30 ', 'QT:     30 ', $this->text());
        $this->assertSame(1, app(BbHomeBrokerPdfParser::class)->parse($broken)['reconciliation']['unparsed_dated_lines']);
    }

    public function test_stage_marks_b3_matches_as_duplicate_candidates_and_writes_nothing_financial(): void
    {
        Storage::fake('local');
        $this->fakePdf($this->text());
        AssetIncomeEvent::create(['ticker' => 'AAAA11', 'payment_date' => '2026-01-24', 'type' => 'fii_income', 'net_amount' => 7.90, 'source' => 'b3_midas', 'source_key' => 'b3inc:a:1']);
        $tx = Transaction::count();
        $imp = app(BrokerStatementImportService::class)->stage(UploadedFile::fake()->createWithContent('bb.pdf', '%PDF-1.4'), 'BB Investimentos', User::factory()->create()->id);
        $this->assertSame('bb_home_broker', $imp->mapping['layout']);
        $rows = $imp->rows()->get();
        $this->assertSame('duplicate_candidate', $rows->firstWhere('net_amount', 7.90)->status);
        $this->assertStringContainsString('Data do evento', implode(' ', $rows->firstWhere('ticker', 'BBBB11')->messages));
        $this->assertSame(3, $rows->where('status', 'ready')->count());
        $this->assertSame(1, AssetIncomeEvent::count());
        $this->assertSame($tx, Transaction::count());
    }

    public function test_preview_command_rolls_back(): void
    {
        $this->fakePdf($this->text());
        $f = tempnam(sys_get_temp_dir(), 'bb').'.pdf';
        file_put_contents($f, '%PDF-1.4');
        $this->artisan('investments:statement-preview', ['file' => $f, '--broker' => 'BB Investimentos'])->expectsOutputToContain('"layout": "bb_home_broker"')->assertSuccessful();
        $this->assertSame(0, BrokerStatementImport::count());
    }
}

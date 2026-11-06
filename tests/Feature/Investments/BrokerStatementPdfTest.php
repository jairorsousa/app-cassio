<?php

namespace Tests\Feature\Investments;

use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Services\Statements\BrokerStatementImportService;
use App\Domains\Investments\Services\Statements\PdfTextExtractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** PDFs sintéticos (ASCII genérico, sem dados reais). Usa o pdftotext real quando instalado. */
class BrokerStatementPdfTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return base_path('tests/fixtures/statements/'.$name);
    }

    private function requirePdftotext(): void
    {
        if (! (new ExecutableFinder)->find('pdftotext')) {
            $this->markTestSkipped('pdftotext (poppler-utils) não instalado neste ambiente.');
        }
    }

    public function test_real_pdftotext_extracts_text_from_synthetic_pdf(): void
    {
        $this->requirePdftotext();
        $r = (new PdfTextExtractor)->extract($this->fixture('sintetico_texto.pdf'));
        $this->assertSame('text_extracted', $r['status']);
        $this->assertStringContainsString('AAAA3', $r['text']);
        $this->assertStringContainsString('Dividendo', $r['text']);
    }

    public function test_real_pdftotext_flags_pdf_without_text(): void
    {
        $this->requirePdftotext();
        $this->assertSame('no_text', (new PdfTextExtractor)->extract($this->fixture('sintetico_sem_texto.pdf'))['status']);
    }

    public function test_pdf_with_text_still_needs_review_and_imports_nothing(): void
    {
        $this->requirePdftotext();
        Storage::fake('local');
        $user = User::factory()->create();
        $imp = app(BrokerStatementImportService::class)->stage(new UploadedFile($this->fixture('sintetico_texto.pdf'), 'x.pdf', 'application/pdf', null, true), 'Corretora X', $user->id);
        $this->assertSame('needs_review', $imp->status);
        $this->assertSame('text_extracted', $imp->text_status);
        $this->assertStringContainsString('sem layout validado', $imp->summary['message']);
        $this->assertGreaterThan(40, $imp->summary['pdf_chars']);
        $this->assertSame(0, $imp->rows()->count());
        $this->assertSame(0, AssetIncomeEvent::count());
    }

    public function test_extractor_failure_or_timeout_degrades_gracefully(): void
    {
        $broken = new class extends PdfTextExtractor {
            protected function binary(): ?string
            {
                return '/bin/false';
            }
        };
        $this->assertSame('no_text', $broken->extract($this->fixture('sintetico_texto.pdf'))['status']);
        $missing = new class extends PdfTextExtractor {
            protected function binary(): ?string
            {
                return '/nao/existe/pdftotext';
            }
        };
        $this->assertSame('no_text', $missing->extract($this->fixture('sintetico_texto.pdf'))['status']);
    }
}

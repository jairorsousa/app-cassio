<?php

namespace App\Domains\Investments\Services\Statements;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Extrai texto de PDF com pdftotext (poppler) quando instalado. Sem extrator ou PDF escaneado → revisão manual. */
class PdfTextExtractor
{
    /** @return array{status: 'text_extracted'|'no_text'|'extractor_unavailable'|'invalid', text: string} */
    public function extract(string $path): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            return ['status' => 'invalid', 'text' => ''];
        }
        $bin = $this->binary();
        if (! $bin) {
            return ['status' => 'extractor_unavailable', 'text' => ''];
        }
        $p = new Process([$bin, '-layout', '-enc', 'UTF-8', $path, '-']);
        $p->setTimeout(60);
        try {
            $p->run();
            $text = $p->isSuccessful() ? trim($p->getOutput()) : '';
        } catch (\Throwable) { // binário quebrado/timeout: trata como sem texto, nunca derruba o upload
            $text = '';
        }

        return ['status' => mb_strlen(preg_replace('/\s+/', '', $text)) >= 40 ? 'text_extracted' : 'no_text', 'text' => $text];
    }

    /**
     * OCR para PDF sem texto (escaneado/imagem): pdftoppm (400 dpi, cinza) + tesseract em português.
     * @return array{status: 'text_extracted'|'no_text'|'extractor_unavailable', text: string}
     */
    public function ocr(string $path, int $maxPages = 10): array
    {
        $finder = new ExecutableFinder;
        $ppm = $finder->find('pdftoppm');
        $tess = $finder->find('tesseract');
        if (! $ppm || ! $tess) {
            return ['status' => 'extractor_unavailable', 'text' => ''];
        }
        $dir = sys_get_temp_dir().'/ocr-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        $text = '';
        try {
            $p = new Process([$ppm, '-r', '400', '-gray', '-png', '-l', (string) $maxPages, $path, $dir.'/p']);
            $p->setTimeout(120);
            $p->run();
            $pages = glob($dir.'/p*.png') ?: [];
            sort($pages, SORT_NATURAL);
            foreach ($pages as $png) {
                $t = new Process([$tess, $png, '-', '-l', 'por', '--psm', '6'], null, ['OMP_THREAD_LIMIT' => '1']);
                $t->setTimeout(120);
                $t->run();
                if ($t->isSuccessful()) {
                    $text .= $t->getOutput()."\n";
                }
            }
        } catch (\Throwable) {
            $text = '';
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $text = trim($text);

        return ['status' => mb_strlen(preg_replace('/\s+/', '', $text)) >= 40 ? 'text_extracted' : 'no_text', 'text' => $text];
    }

    protected function binary(): ?string
    {
        return (new ExecutableFinder)->find('pdftotext');
    }
}

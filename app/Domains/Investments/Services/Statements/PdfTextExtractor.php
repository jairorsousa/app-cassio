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

    protected function binary(): ?string
    {
        return (new ExecutableFinder)->find('pdftotext');
    }
}

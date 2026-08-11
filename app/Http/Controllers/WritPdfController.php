<?php

namespace App\Http\Controllers;

use App\Domains\Writs\Models\Writ;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WritPdfController extends Controller
{
    public function __invoke(Writ $writ): Response
    {
        $writ->load([
            'assignors.contact',
            'history.user',
            'transactions',
        ]);

        $identifier = Str::of($writ->process_number ?: (string) $writ->id)
            ->replace('.', '-');
        $filename = 'requisitorio-'.Str::slug($identifier).'.pdf';

        return Pdf::loadView('pdf.writs.show', [
            'writ' => $writ,
            'generatedAt' => now(),
        ])
            ->setPaper('a4')
            ->download($filename);
    }
}

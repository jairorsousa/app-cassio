<?php

namespace App\Http\Controllers;

use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Services\LoanService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Download autenticado do comprovante de empréstimo (disco privado). */
class LoanAttachmentController extends Controller
{
    public function __invoke(Loan $loan): StreamedResponse
    {
        abort_unless($loan->attachment_path, 404);
        $disk = Storage::disk(LoanService::ATTACHMENT_DISK);
        abort_unless($disk->exists($loan->attachment_path), 404);

        return $disk->download($loan->attachment_path, $loan->attachment_original_name ?: 'comprovante', [
            'Content-Type' => $loan->attachment_mime ?: 'application/octet-stream',
        ]);
    }
}

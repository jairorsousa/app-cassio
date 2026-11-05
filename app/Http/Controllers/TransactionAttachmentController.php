<?php

namespace App\Http\Controllers;

use App\Domains\Banking\Models\TransactionAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Download autenticado do comprovante (a rota fica no grupo auth). */
class TransactionAttachmentController extends Controller
{
    public function __invoke(TransactionAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk(TransactionAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

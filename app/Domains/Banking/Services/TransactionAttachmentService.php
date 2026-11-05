<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Models\TransactionAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Comprovantes de lançamentos: disco privado, nome aleatório, só PDF/JPG/PNG. */
class TransactionAttachmentService
{
    public const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function store(Transaction $transaction, UploadedFile $file): TransactionAttachment
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($mime, self::ALLOWED_MIMES, true) || ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            throw new \DomainException('O comprovante deve ser PDF, JPG ou PNG.');
        }
        if ($file->getSize() > TransactionAttachment::MAX_KB * 1024) {
            throw new \DomainException('O comprovante deve ter no máximo 10 MB.');
        }
        $path = $file->storeAs('comprovantes/'.$transaction->id, Str::uuid().'.'.$extension, TransactionAttachment::DISK);

        return $transaction->attachments()->create([
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: 'comprovante.'.$extension, 0, 200),
            'mime' => $mime, 'size' => (int) $file->getSize(), 'user_id' => auth()->id(),
        ]);
    }

    public function delete(TransactionAttachment $attachment): void
    {
        Storage::disk(TransactionAttachment::DISK)->delete($attachment->path);
        $attachment->delete();
    }
}

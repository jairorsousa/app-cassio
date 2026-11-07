<?php

namespace App\Support;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Jobs\SendTransmissionRecipient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransmissionDispatcher
{
    public const MIN_GAP = 45;

    public const MAX_GAP = 90;

    public const AUDIO_MIME = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg'];

    /** Cria o lote + destinatários (ordem embaralhada, 45–90 s entre cada) e agenda uma tarefa por pessoa. */
    public static function start(?int $userId, string $body, ?UploadedFile $media, array $audience, array $labels): TransmissionBatch
    {
        $batch = DB::transaction(function () use ($userId, $body, $media, $audience, $labels) {
            $b = TransmissionBatch::create([
                'created_by' => $userId, 'labels' => array_values($labels), 'body' => $body, 'status' => 'queued',
                'recipients_count' => count($audience['recipients']), 'duplicates_omitted' => $audience['duplicates'],
                'invalid_omitted' => $audience['missing_phone'] + $audience['invalid_phone'],
            ]);
            if ($media) {
                $ext = strtolower($media->getClientOriginalExtension());
                $path = $media->storeAs('transmissions/'.$b->id, Str::random(32).'.'.$ext, 'local');
                $b->update(['media_path' => $path, 'media_name' => $media->getClientOriginalName(), 'media_mime' => self::AUDIO_MIME[$ext] ?? $media->getMimeType()]);
            }
            $offset = 5;
            foreach (collect($audience['recipients'])->shuffle()->values() as $i => $p) {
                if ($i > 0) {
                    $offset += random_int(self::MIN_GAP, self::MAX_GAP);
                }
                TransmissionRecipient::create([
                    'batch_id' => $b->id, 'contact_id' => $p['contact_id'], 'name' => $p['name'], 'phone' => $p['phone'], 'phone_hash' => $p['phone_hash'],
                    'labels' => $p['labels'], 'position' => $i + 1, 'status' => 'pending', 'scheduled_at' => now()->addSeconds($offset),
                ]);
            }

            return $b;
        });

        foreach ($batch->recipients()->orderBy('position')->get(['id', 'scheduled_at']) as $r) {
            SendTransmissionRecipient::dispatch($r->id)->delay($r->scheduled_at);
        }

        return $batch;
    }
}

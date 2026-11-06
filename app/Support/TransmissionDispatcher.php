<?php

namespace App\Support;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Jobs\SendTransmissionRecipient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TransmissionDispatcher
{
    public const MIN_GAP = 45;

    public const MAX_GAP = 90;

    public const AUDIO_MIME = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg'];

    /** Cria o lote + destinatários (ordem embaralhada, 45–90 s entre cada) e agenda uma tarefa por pessoa. */
    public static function start(?int $userId, string $body, ?UploadedFile $media, array $audience, array $labels, ?array $storedMedia = null, ?int $scheduleId = null): TransmissionBatch
    {
        // Confere de novo a lista "Não receber" no momento de criar o lote.
        $audience = \App\Support\TransmissionAudience::withoutSuppressed($audience);
        $batch = DB::transaction(function () use ($userId, $body, $media, $audience, $labels, $storedMedia, $scheduleId) {
            $b = TransmissionBatch::create([
                'created_by' => $userId, 'schedule_id' => $scheduleId, 'labels' => array_values($labels), 'body' => $body, 'status' => 'queued',
                'recipients_count' => count($audience['recipients']), 'duplicates_omitted' => $audience['duplicates'],
                'invalid_omitted' => $audience['missing_phone'] + $audience['invalid_phone'], 'suppressed_omitted' => $audience['suppressed'] ?? 0,
            ]);
            if ($storedMedia) {
                $path = 'transmissions/'.$b->id.'/'.basename($storedMedia['path']);
                Storage::disk('local')->copy($storedMedia['path'], $path);
                $b->update(['media_path' => $path, 'media_name' => $storedMedia['name'], 'media_mime' => $storedMedia['mime']]);
            }
            if ($media) {
                $ext = strtolower($media->getClientOriginalExtension());
                $path = $media->storeAs('transmissions/'.$b->id, Str::random(32).'.'.$ext, 'local');
                $b->update(['media_path' => $path, 'media_name' => $media->getClientOriginalName(), 'media_mime' => self::AUDIO_MIME[$ext] ?? $media->getMimeType()]);
            }
            self::createRecipients($b, $audience['recipients']);

            return $b;
        });
        self::schedule($batch);

        return $batch;
    }

    private static function createRecipients(TransmissionBatch $b, iterable $people): void
    {
        foreach (collect($people)->shuffle()->values() as $i => $p) {
            TransmissionRecipient::create([
                'batch_id' => $b->id, 'contact_id' => $p['contact_id'], 'name' => $p['name'], 'phone' => $p['phone'], 'phone_hash' => $p['phone_hash'],
                'labels' => $p['labels'], 'position' => $i + 1, 'status' => 'pending',
            ]);
        }
    }

    /** Agenda (ou reagenda) os pendentes: 5 s para o primeiro, depois 45–90 s entre cada. Tarefas antigas perdem a validade pelo token. */
    private static function schedule(TransmissionBatch $b): void
    {
        $offset = 5;
        $jobs = [];
        foreach ($b->recipients()->where('status', 'pending')->orderBy('position')->get(['id']) as $i => $r) {
            if ($i > 0) {
                $offset += random_int(self::MIN_GAP, self::MAX_GAP);
            }
            $token = Str::random(32);
            $at = now()->addSeconds($offset);
            TransmissionRecipient::whereKey($r->id)->where('status', 'pending')->update(['dispatch_token' => $token, 'scheduled_at' => $at]);
            $jobs[] = [$r->id, $token, $at];
        }
        foreach ($jobs as [$id, $token, $at]) {
            SendTransmissionRecipient::dispatch($id, $token)->delay($at);
        }
    }

    /** Lote do usuário logado (404 para lote de outra pessoa). */
    public static function owned(int $batchId, ?int $userId): TransmissionBatch
    {
        return TransmissionBatch::where('created_by', $userId)->findOrFail($batchId);
    }

    public static function pause(int $batchId, ?int $userId): bool
    {
        return DB::transaction(function () use ($batchId, $userId) {
            $b = TransmissionBatch::where('created_by', $userId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, ['queued', 'running'], true)) {
                return false;
            }
            $b->update(['status' => 'paused', 'paused_at' => now(), 'controlled_by' => $userId]);

            return true;
        });
    }

    public static function resume(int $batchId, ?int $userId): bool
    {
        $b = DB::transaction(function () use ($batchId, $userId) {
            $b = TransmissionBatch::where('created_by', $userId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if ($b->status !== 'paused') {
                return null;
            }
            $b->update(['status' => 'running', 'paused_at' => null, 'controlled_by' => $userId]);

            return $b;
        });
        if (! $b) {
            return false;
        }
        self::schedule($b);
        TransmissionBatch::finalizeIfDone($b->id);

        return true;
    }

    public static function cancel(int $batchId, ?int $userId): bool
    {
        $ok = DB::transaction(function () use ($batchId, $userId) {
            $b = TransmissionBatch::where('created_by', $userId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, ['queued', 'running', 'paused'], true)) {
                return false;
            }
            $b->recipients()->where('status', 'pending')->update(['status' => 'cancelled', 'dispatch_token' => null, 'finished_at' => now()]);
            $b->update(['status' => 'cancelled', 'cancelled_at' => now(), 'paused_at' => null, 'controlled_by' => $userId]);

            return true;
        });
        if ($ok) {
            TransmissionBatch::finalizeIfDone($batchId);
        }

        return $ok;
    }

    /** Quantos podem ser reenviados (só "Falhou") e o motivo de bloqueio, se houver. */
    public static function retryInfo(TransmissionBatch $b): array
    {
        $failed = $b->recipients()->where('status', 'failed')->count();
        $reason = null;
        if (! $b->isFinal()) {
            $reason = 'Aguarde a transmissão terminar (ou cancele) para reenviar as falhas.';
        } elseif ($failed === 0) {
            $reason = 'Não há destinatários com falha.';
        } elseif ($b->retries()->exists()) {
            $reason = 'As falhas desta transmissão já foram reenviadas.';
        } elseif ($b->media_name && ($b->media_removed_at || ! $b->media_path || ! Storage::disk('local')->exists($b->media_path))) {
            $reason = 'Não é possível reenviar: o anexo original foi removido. Crie uma nova transmissão e anexe o arquivo de novo.';
        }

        return ['failed' => $failed, 'unconfirmed' => $b->recipients()->where('status', 'unconfirmed')->count(), 'blocked' => $reason];
    }

    /** Novo lote ligado ao original, só com os destinatários "Falhou". O lote original não é alterado. */
    public static function retryFailed(int $batchId, ?int $userId): TransmissionBatch
    {
        $new = DB::transaction(function () use ($batchId, $userId) {
            $orig = TransmissionBatch::where('created_by', $userId)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            $info = self::retryInfo($orig);
            if ($info['blocked']) {
                throw new \RuntimeException($info['blocked']);
            }
            $blocked = \App\Domains\Broadcasts\Models\TransmissionSuppression::activeHashes($orig->recipients()->where('status', 'failed')->pluck('phone_hash')->all());
            $people = $orig->recipients()->where('status', 'failed')->orderBy('position')->get()
                ->reject(fn ($r) => isset($blocked[$r->phone_hash]))
                ->map(fn ($r) => ['contact_id' => $r->contact_id, 'name' => $r->name, 'phone' => $r->phone, 'phone_hash' => $r->phone_hash, 'labels' => $r->labels]);
            if ($people->isEmpty()) {
                throw new \RuntimeException('Todos com falha estão na lista "Não receber".');
            }
            $b = TransmissionBatch::create([
                'created_by' => $userId, 'retry_of_id' => $orig->id, 'labels' => $orig->labels, 'body' => $orig->body, 'status' => 'queued',
                'recipients_count' => $people->count(), 'duplicates_omitted' => 0, 'invalid_omitted' => 0, 'suppressed_omitted' => count($blocked),
            ]);
            if ($orig->media_name) {
                $path = 'transmissions/'.$b->id.'/'.basename($orig->media_path);
                Storage::disk('local')->copy($orig->media_path, $path);
                $b->update(['media_path' => $path, 'media_name' => $orig->media_name, 'media_mime' => $orig->media_mime]);
            }
            self::createRecipients($b, $people);

            return $b;
        });
        TransmissionBatch::whereKey($batchId)->first()?->releaseMedia();
        self::schedule($new);

        return $new;
    }
}

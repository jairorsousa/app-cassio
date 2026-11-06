<?php

namespace App\Domains\Broadcasts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TransmissionBatch extends Model
{
    protected $fillable = ['created_by', 'retry_of_id', 'labels', 'body', 'media_path', 'media_name', 'media_mime', 'status', 'paused_at', 'cancelled_at', 'controlled_by', 'schedule_id', 'recipients_count', 'duplicates_omitted', 'invalid_omitted', 'suppressed_omitted', 'media_removed_at', 'finished_at'];

    protected $casts = ['labels' => 'array', 'media_removed_at' => 'datetime', 'finished_at' => 'datetime', 'paused_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public const STATUS_LABELS = ['queued' => 'Na fila', 'running' => 'Enviando', 'paused' => 'Pausada', 'cancelled' => 'Cancelada', 'done' => 'Concluída', 'done_with_errors' => 'Concluída com pendências'];

    /** Estados em que nada mais será enviado. */
    public const FINAL = ['done', 'done_with_errors', 'cancelled'];

    public function recipients(): HasMany
    {
        return $this->hasMany(TransmissionRecipient::class, 'batch_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function media(): ?array
    {
        return $this->media_path ? ['path' => $this->media_path, 'name' => $this->media_name, 'mime' => $this->media_mime] : null;
    }

    public function mediaKind(): ?string
    {
        if (! $this->media_name) {
            return null;
        }

        return match (strtok((string) $this->media_mime, '/')) {
            'image' => 'Imagem', 'video' => 'Vídeo', 'audio' => 'Áudio', default => 'Arquivo',
        };
    }

    public function counts(): array
    {
        $c = $this->recipients()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return collect(TransmissionRecipient::STATUSES)->mapWithKeys(fn ($l, $k) => [$k => (int) ($c[$k] ?? 0)])->all();
    }

    /**
     * Fecha o lote quando ninguém está pendente/enviando (lote pausado com pendentes continua aberto).
     * O anexo é apagado no fechamento, exceto se houver destinatários "Falhou" (podem ser reenviados).
     */
    public static function finalizeIfDone(int $id): void
    {
        DB::transaction(function () use ($id) {
            $b = self::whereKey($id)->lockForUpdate()->first();
            if (! $b || in_array($b->status, ['done', 'done_with_errors'], true)) {
                return;
            }
            // "enviando" há mais de 10 min = processo caiu no meio: sem certeza, não reenviar.
            $b->recipients()->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(10))
                ->update(['status' => 'unconfirmed', 'error' => 'Processo interrompido durante o envio', 'finished_at' => now()]);
            if ($b->recipients()->whereIn('status', ['pending', 'sending'])->exists()) {
                return;
            }
            if ($b->status === 'cancelled' && $b->finished_at) {
                return;
            }
            $problems = $b->recipients()->whereIn('status', ['failed', 'unconfirmed'])->exists();
            $keepMedia = $b->recipients()->where('status', 'failed')->exists();
            $update = ['status' => $b->status === 'cancelled' ? 'cancelled' : ($problems ? 'done_with_errors' : 'done'), 'finished_at' => now()];
            if ($b->media_path && ! $keepMedia) {
                Storage::disk('local')->deleteDirectory('transmissions/'.$b->id);
                $update['media_removed_at'] = now();
            }
            $b->update($update);
        });
    }

    /** Remove o anexo guardado (usado depois que as falhas foram passadas para um reenvio). */
    public function releaseMedia(): void
    {
        if ($this->media_path && ! $this->media_removed_at) {
            Storage::disk('local')->deleteDirectory('transmissions/'.$this->id);
            $this->update(['media_removed_at' => now()]);
        }
    }
}

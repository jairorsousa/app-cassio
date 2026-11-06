<?php

namespace App\Domains\Broadcasts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TransmissionBatch extends Model
{
    protected $fillable = ['created_by', 'labels', 'body', 'media_path', 'media_name', 'media_mime', 'status', 'recipients_count', 'duplicates_omitted', 'invalid_omitted', 'media_removed_at', 'finished_at'];

    protected $casts = ['labels' => 'array', 'media_removed_at' => 'datetime', 'finished_at' => 'datetime'];

    public const STATUS_LABELS = ['queued' => 'Na fila', 'running' => 'Enviando', 'done' => 'Concluída', 'done_with_errors' => 'Concluída com pendências'];

    public function recipients(): HasMany
    {
        return $this->hasMany(TransmissionRecipient::class, 'batch_id');
    }

    public function media(): ?array
    {
        return $this->media_path ? ['path' => $this->media_path, 'name' => $this->media_name, 'mime' => $this->media_mime] : null;
    }

    public function counts(): array
    {
        $c = $this->recipients()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return collect(TransmissionRecipient::STATUSES)->mapWithKeys(fn ($l, $k) => [$k => (int) ($c[$k] ?? 0)])->all();
    }

    /** Fecha o lote quando ninguém está pendente/enviando; só então apaga o anexo. */
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
            $problems = $b->recipients()->whereIn('status', ['failed', 'unconfirmed'])->exists();
            if ($b->media_path) {
                Storage::disk('local')->deleteDirectory('transmissions/'.$b->id);
            }
            $b->update(['status' => $problems ? 'done_with_errors' : 'done', 'finished_at' => now(), 'media_removed_at' => $b->media_path ? now() : null]);
        });
    }
}

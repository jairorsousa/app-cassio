<?php

namespace App\Domains\Broadcasts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionSchedule extends Model
{
    protected $fillable = ['created_by', 'kind', 'status', 'labels', 'body', 'media_path', 'media_name', 'media_mime', 'first_run_at', 'first_period', 'next_run_at', 'runs_count', 'last_week_key', 'last_batch_id', 'last_error', 'last_run_at'];

    protected $casts = ['labels' => 'array', 'first_run_at' => 'datetime', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];

    public const STATUS_LABELS = ['active' => 'Agendada', 'paused' => 'Pausada', 'cancelled' => 'Cancelada', 'done' => 'Executada', 'failed' => 'Não executada'];

    public const KIND_LABELS = ['once' => 'Data e horário', 'weekly' => 'Toda semana (alternando)'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media(): ?array
    {
        return $this->media_path ? ['path' => $this->media_path, 'name' => $this->media_name, 'mime' => $this->media_mime] : null;
    }
}

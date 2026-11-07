<?php

namespace App\Domains\Broadcasts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionRecipient extends Model
{
    protected $fillable = ['batch_id', 'contact_id', 'name', 'phone', 'phone_hash', 'labels', 'position', 'status', 'error', 'external_message_id', 'scheduled_at', 'attempted_at', 'finished_at'];

    protected $casts = ['phone' => 'encrypted', 'labels' => 'array', 'scheduled_at' => 'datetime', 'attempted_at' => 'datetime', 'finished_at' => 'datetime'];

    protected $hidden = ['phone'];

    /** "sent" = aceito pelo Chatwoot (não comprova entrega/leitura). */
    public const STATUSES = ['pending' => 'Aguardando', 'sending' => 'Enviando', 'sent' => 'Aceita pelo Chatwoot', 'failed' => 'Falhou (não enviada)', 'unconfirmed' => 'Sem confirmação'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TransmissionBatch::class, 'batch_id');
    }
}

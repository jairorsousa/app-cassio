<?php

namespace App\Domains\Contacts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de troca de CPF/CNPJ ou de exclusão de e-mail, pendente até o administrador decidir. */
class IdentityChangeRequest extends Model
{
    public const TYPE_DOCUMENT = 'document';

    public const TYPE_EMAIL_REMOVE = 'email_remove';

    public const STATUS_LABELS = ['pending' => 'Aguardando aprovação', 'approved' => 'Aprovado', 'rejected' => 'Recusado', 'cancelled' => 'Cancelado'];

    protected $fillable = ['contact_id', 'requested_by', 'type', 'old_value', 'new_value', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed()->withoutGlobalScope('not_test');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_DOCUMENT ? 'Troca de CPF/CNPJ' : 'Exclusão de e-mail';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}

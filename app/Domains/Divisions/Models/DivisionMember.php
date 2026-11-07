<?php

namespace App\Domains\Divisions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DivisionMember extends Model
{
    protected $fillable = ['group_id', 'name', 'email', 'phone', 'user_id', 'is_owner', 'active', 'approval_status', 'requested_by'];

    protected $casts = ['is_owner' => 'boolean', 'active' => 'boolean'];

    protected $attributes = ['active' => true];

    /** "+5585999998888" → "(85) 99999-8888". */
    public function phoneDisplay(): string
    {
        return $this->phone ? \App\Support\BrazilianDocuments::formatPhone(substr((string) $this->phone, 3)) : '';
    }

    /** Pessoa externa pedida por participante: fica fora das divisões e sem convite até o Cassio aprovar. */
    public function isPending(): bool
    {
        return $this->approval_status === 'pending';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DivisionGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Chave para somar o saldo da mesma pessoa em vários grupos. */
    public function personKey(): string
    {
        if ($this->is_owner) {
            return 'owner';
        }
        if ($this->user_id) {
            return 'u'.$this->user_id;
        }

        return $this->email ? 'e'.mb_strtolower($this->email) : 'm'.$this->id;
    }
}

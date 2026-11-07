<?php

namespace App\Domains\Divisions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DivisionMember extends Model
{
    protected $fillable = ['group_id', 'name', 'email', 'user_id', 'is_owner'];

    protected $casts = ['is_owner' => 'boolean'];

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

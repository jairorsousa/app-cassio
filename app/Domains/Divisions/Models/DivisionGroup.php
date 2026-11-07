<?php

namespace App\Domains\Divisions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DivisionGroup extends Model
{
    use SoftDeletes;

    public const TYPES = ['sociedade' => 'Sociedade', 'familia' => 'Família', 'amigos' => 'Amigos'];

    public const STATUS = ['pending' => 'Aguardando aprovação', 'approved' => 'Ativo', 'rejected' => 'Recusado'];

    protected $fillable = ['name', 'type', 'status', 'requires_approval', 'notes', 'rejection_reason', 'requested_by', 'created_by', 'decided_at'];

    protected $casts = ['requires_approval' => 'boolean', 'decided_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(DivisionMember::class, 'group_id')->orderByDesc('is_owner')->orderBy('name');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(DivisionExpense::class, 'group_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(DivisionSettlement::class, 'group_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DivisionAudit::class, 'group_id');
    }

    /** Grupos que o usuário pode ver: administrador vê todos; participante só os seus. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->whereHas('members', fn ($m) => $m->whereNull('approval_status')->where(fn ($m) => $m->where('user_id', $user->id)
            ->orWhere(fn ($m) => $m->whereNull('user_id')->whereRaw('lower(email) = ?', [mb_strtolower($user->email)]))));
    }

    public function memberFor(User $user): ?DivisionMember
    {
        $ok = $this->members->filter(fn ($m) => ! $m->isPending());

        return $ok->first(fn ($m) => $m->user_id === $user->id)
            ?? $ok->first(fn ($m) => $m->user_id === null && $m->email && mb_strtolower($m->email) === mb_strtolower($user->email));
    }

    public function ownerMember(): ?DivisionMember
    {
        return $this->members->firstWhere('is_owner', true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}

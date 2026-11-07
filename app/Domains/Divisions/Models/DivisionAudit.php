<?php

namespace App\Domains\Divisions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DivisionAudit extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'group_created' => 'Grupo criado', 'group_requested' => 'Grupo solicitado', 'group_approved' => 'Grupo aprovado',
        'group_rejected' => 'Grupo recusado', 'group_updated' => 'Grupo alterado', 'member_added' => 'Participante adicionado',
        'member_invited' => 'Convite gerado', 'member_removed' => 'Participante removido', 'expense_created' => 'Despesa lançada', 'expense_updated' => 'Despesa alterada',
        'expense_approved' => 'Despesa aprovada', 'expense_rejected' => 'Despesa recusada', 'expense_deleted' => 'Despesa excluída',
        'expense_linked' => 'Vinculada ao Financeiro', 'settlement_created' => 'Acerto registrado', 'settlement_deleted' => 'Acerto excluído',
    ];

    protected $fillable = ['group_id', 'expense_id', 'settlement_id', 'user_id', 'action', 'details'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }
}

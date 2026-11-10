<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['created' => 'Criou', 'updated' => 'Editou', 'deleted' => 'Excluiu', 'login' => 'Entrou', 'logout' => 'Saiu', 'login_email_changed' => 'Trocou o e-mail de acesso'];

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}

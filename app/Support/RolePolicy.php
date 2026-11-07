<?php

namespace App\Support;

use App\Models\User;

/**
 * Perfis: Administrador (is_admin, tudo), Operador (lança e edita; não exclui cadastros críticos)
 * e Somente leitura (vê, filtra e navega; nenhuma ação que grave).
 */
class RolePolicy
{
    public const ROLES = ['operator' => 'Operador', 'viewer' => 'Somente leitura'];

    /** Ações Livewire permitidas ao Somente leitura: navegação, filtros, abrir/fechar painéis. */
    private const VIEWER_ALLOWED = '/^(\$refresh|\$set|\$toggle|\$parent|render|logout|load|show|hide|open|close|toggle|expand|collapse|select|filter|clear|reset|sort|search|go|next|previous|prev|gotoPage|nextPage|previousPage|setPage|resetPage|refresh|view|preview|period|month|year|tab|switch|change|apply|pick|focus|cancel|dismiss|lazyLoad|__lazyLoad)/i';

    /** Exclusões bloqueadas ao Operador nos cadastros críticos. */
    private const OPERATOR_BLOCKED_METHOD = '/^(delete|destroy|remove|forceDelete|purge|revoke|block|deactivate|archive)/i';

    /** Cadastros críticos (não os movimentos): contatos, corretores, requisitórios, contas, cartões, categorias, configurações. */
    private const CRITICAL_COMPONENTS = '/^(contacts\.|brokers\.(index|form|show)$|writs\.|banking\.(accounts|cards|categories)\.|settings\.|assets\.)/i';

    public static function role(?User $user): string
    {
        if (! $user) {
            return 'viewer';
        }

        return $user->isAdmin() ? 'admin' : (in_array($user->role ?? null, ['operator', 'viewer'], true) ? $user->role : 'operator');
    }

    public static function label(?User $user): string
    {
        $r = self::role($user);

        return $r === 'admin' ? 'Administrador' : self::ROLES[$r];
    }

    public static function isReadOnly(?User $user): bool
    {
        return $user !== null && self::role($user) === 'viewer';
    }

    /** Motivo do bloqueio, ou null quando a ação é permitida. */
    public static function blockReason(?User $user, string $component, string $method): ?string
    {
        if (! $user) {
            return null;
        }
        $role = self::role($user);
        if ($role === 'viewer' && ! preg_match(self::VIEWER_ALLOWED, $method)) {
            return 'Seu perfil é Somente leitura: esta ação não é permitida.';
        }
        if ($role === 'operator' && preg_match(self::OPERATOR_BLOCKED_METHOD, $method) && preg_match(self::CRITICAL_COMPONENTS, $component)) {
            return 'Seu perfil é Operador: exclusão de cadastros é só para o administrador.';
        }

        return null;
    }
}

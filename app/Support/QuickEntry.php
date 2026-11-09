<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Lançamento rápido global ("+ Lançar" no topo, botão "+" no celular, atalhos do painel).
 * O formulário é o MESMO de Lançamentos (componente banking.transactions.index em modo quick).
 */
class QuickEntry
{
    /** Ação principal de cada módulo, mostrada junto com "Novo lançamento" (prefixo de rota => [módulo, rótulo, rota, ícone]). */
    private const MODULE_ACTIONS = [
        'writs.' => ['requisitorio', 'Novo requisitório', 'writs.create', 'gavel'],
        'investments.' => ['investimentos', 'Registrar movimentação', 'investments.operations.index', 'swap_horiz'],
        'brokers.' => ['corretores', 'Novo corretor', 'brokers.create', 'badge'],
        'contacts.' => ['contatos', 'Novo contato', 'contacts.create', 'person_add'],
    ];

    /** Quem pode lançar: acesso ao Financeiro e perfil que não seja "Somente leitura". */
    public static function enabled(?User $user): bool
    {
        return $user !== null
            && Access::allows($user, ['financeiro'])
            && ! RolePolicy::isReadOnly($user);
    }

    /**
     * Ação principal do módulo da rota atual (ou null em Financeiro/painel e telas sem ação própria).
     *
     * @return array{label: string, href: string, icon: string}|null
     */
    public static function moduleAction(?string $routeName, ?User $user): ?array
    {
        if (! $routeName || ! $user) {
            return null;
        }
        foreach (self::MODULE_ACTIONS as $prefix => [$module, $label, $route, $icon]) {
            if (str_starts_with($routeName, $prefix) && $routeName !== $route && Route::has($route) && Access::allows($user, [$module])) {
                return ['label' => $label, 'href' => route($route), 'icon' => $icon];
            }
        }

        return null;
    }
}

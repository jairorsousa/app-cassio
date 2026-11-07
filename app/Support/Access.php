<?php

namespace App\Support;

use App\Models\User;

/** Módulos do sistema e o mapa de rotas/componentes → módulo (usado no menu, nas rotas e no Livewire). */
final class Access
{
    public const MODULES = [
        'dashboard' => 'Dashboard',
        'financeiro' => 'Financeiro',
        'investimentos' => 'Investimentos (inclui Bens)',
        'requisitorio' => 'Requisitório',
        'corretores' => 'Corretores',
        'contatos' => 'Contatos',
        'sociedade' => 'Sociedade',
        'divisoes' => 'Divisões',
        'transmissao' => 'Transmissão',
    ];

    public const ADMIN = 'admin';

    /** Prefixo de NOME de rota → módulo. */
    private const ROUTES = [
        'dashboard' => 'dashboard',
        'banking.' => 'financeiro',
        'investments.' => 'investimentos',
        'bens.' => 'investimentos',
        'writs.' => 'requisitorio',
        'brokers.' => 'corretores',
        'contacts.' => 'contatos',
        'partnership.' => 'sociedade',
        'divisions.' => 'divisoes',
        'broadcasts.' => 'transmissao',
        'settings.users' => self::ADMIN,
        'settings.audit' => self::ADMIN,
    ];

    /** Prefixo de NOME de componente Livewire → módulo. */
    private const COMPONENTS = [
        'dashboard.' => 'dashboard',
        'banking.' => 'financeiro',
        'investments.' => 'investimentos',
        'bens.' => 'investimentos',
        'writs.' => 'requisitorio',
        'brokers.' => 'corretores',
        'contacts.' => 'contatos',
        'partnership.' => 'sociedade',
        'divisions.' => 'divisoes',
        'broadcasts.' => 'transmissao',
        'settings.users' => self::ADMIN,
        'settings.audit' => self::ADMIN,
    ];

    /** Componentes usados dentro de páginas de outro módulo: basta ter um dos módulos listados. */
    private const SHARED_COMPONENTS = [
        'banking.regime-switch' => ['dashboard', 'financeiro'],
    ];

    private static function match(string $name, array $map): ?array
    {
        foreach ($map as $prefix => $module) {
            $hit = str_ends_with($prefix, '.') ? str_starts_with($name, $prefix) : ($name === $prefix || str_starts_with($name, $prefix.'.'));
            if ($hit) {
                return [$module];
            }
        }

        return null;
    }

    /** @return list<string>|null null = livre para qualquer usuário ativo */
    public static function forRoute(?string $name): ?array
    {
        return $name === null ? null : self::match($name, self::ROUTES);
    }

    /** @return list<string>|null */
    public static function forComponent(?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        return self::SHARED_COMPONENTS[$name] ?? self::match($name, self::COMPONENTS);
    }

    public static function allows(?User $user, ?array $modules): bool
    {
        if ($modules === null) {
            return true;
        }
        if (! $user || ! $user->isActive()) {
            return false;
        }
        foreach ($modules as $m) {
            if ($m === self::ADMIN ? $user->isAdmin() : $user->canAccessModule($m)) {
                return true;
            }
        }

        return false;
    }

    /** Primeira tela permitida (para "/" e depois do login). */
    public static function homeRoute(?User $user): string
    {
        $map = ['dashboard' => 'dashboard', 'financeiro' => 'banking.dashboard', 'investimentos' => 'investments.dashboard', 'requisitorio' => 'writs.kanban', 'corretores' => 'brokers.index', 'contatos' => 'contacts.index', 'sociedade' => 'partnership.index', 'divisoes' => 'divisions.index', 'transmissao' => 'broadcasts.index'];
        foreach ($map as $m => $route) {
            if ($user?->canAccessModule($m)) {
                return $route;
            }
        }

        return 'settings';
    }

    /** @return list<string> só chaves válidas, na ordem oficial */
    public static function clean(array $modules): array
    {
        return array_values(array_filter(array_keys(self::MODULES), fn ($k) => in_array($k, $modules, true)));
    }
}

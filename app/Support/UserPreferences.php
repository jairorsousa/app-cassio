<?php

namespace App\Support;

use App\Models\User;

/** Preferências visuais por usuário (lista fechada; valores fora da lista são rejeitados). */
class UserPreferences
{
    public const DEFAULTS = ['theme' => 'light', 'accent' => 'laranja', 'text_size' => 'padrao', 'density' => 'confortavel', 'home' => 'dashboard'];

    public const THEMES = ['light' => 'Claro', 'dark' => 'Escuro', 'auto' => 'Automático (segue o sistema)'];

    public const TEXT_SIZES = ['padrao' => 'Padrão', 'grande' => 'Grande', 'extra' => 'Extra grande'];

    public const DENSITIES = ['confortavel' => 'Confortável', 'compacta' => 'Compacta'];

    public const HOMES = ['dashboard' => ['Painel', 'dashboard'], 'financeiro' => ['Financeiro (lançamentos)', 'banking.transactions.index'], 'investimentos' => ['Investimentos', 'investments.dashboard']];

    /** [rótulo, claro [100, 500, 600], escuro [100, 500, 600]] */
    public const ACCENTS = [
        'laranja' => ['Laranja (padrão)', ['#fff0e0', '#ff6f00', '#e56300'], ['#3d2800', '#ff8c33', '#ff6f00']],
        'azul' => ['Azul', ['#e8f0fe', '#1a73e8', '#1557b0'], ['#16263f', '#5b9cf5', '#3b82f6']],
        'verde' => ['Verde', ['#e6f6ee', '#11875a', '#0d6e49'], ['#132e22', '#34c98a', '#22b072']],
        'roxo' => ['Roxo', ['#f1ebfe', '#7c3aed', '#6527c9'], ['#2a1f45', '#a78bfa', '#8b5cf6']],
        'rosa' => ['Rosa', ['#fde8f1', '#d6336c', '#b3245a'], ['#3d1a29', '#f06595', '#e64980']],
        'petroleo' => ['Petróleo', ['#e3f4f4', '#0e7c86', '#0a646c'], ['#123033', '#2bb3be', '#1c9aa4']],
    ];

    /** Itens por página nas listas (Financeiro › Lançamentos): só estes valores. */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const PER_PAGE_DEFAULT = 25;

    public static function perPage(?User $user, string $list = 'transactions'): int
    {
        $v = (int) (((array) ($user?->preferences ?? []))['per_page'][$list] ?? 0);

        return in_array($v, self::PER_PAGE_OPTIONS, true) ? $v : self::PER_PAGE_DEFAULT;
    }

    /** Guarda a escolha do usuário (sem mexer nas outras preferências). Valor fora da lista volta ao padrão. */
    /** Ordenação da lista de Lançamentos: coluna => rótulo. Padrão: data mais nova em cima. */
    public const SORT_COLUMNS = ['date' => 'Data', 'description' => 'Descrição', 'category' => 'Categoria', 'account' => 'Conta', 'amount' => 'Valor'];

    public const SORT_DEFAULT = ['date', 'desc'];

    /** @return array{0: string, 1: string} [coluna, direção] validados (lista fechada; inválido volta ao padrão). */
    public static function sort(?User $user, string $list = 'transactions'): array
    {
        $v = (array) (((array) ($user?->preferences ?? []))['sort'][$list] ?? []);

        return self::validSort((string) ($v['by'] ?? ''), (string) ($v['dir'] ?? ''));
    }

    /** @return array{0: string, 1: string} */
    public static function validSort(string $by, string $dir): array
    {
        if (! array_key_exists($by, self::SORT_COLUMNS)) {
            return self::SORT_DEFAULT;
        }

        return [$by, in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc'];
    }

    /** @return array{0: string, 1: string} */
    public static function setSort(?User $user, string $by, string $dir, string $list = 'transactions'): array
    {
        [$by, $dir] = self::validSort($by, $dir);
        if ($user) {
            $prefs = (array) ($user->fresh()?->preferences ?? $user->preferences ?? []);
            $prefs['sort'] = array_merge((array) ($prefs['sort'] ?? []), [$list => ['by' => $by, 'dir' => $dir]]);
            $user->forceFill(['preferences' => $prefs])->saveQuietly();
        }

        return [$by, $dir];
    }

    public static function setPerPage(?User $user, int $value, string $list = 'transactions'): int
    {
        $value = in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::PER_PAGE_DEFAULT;
        if ($user) {
            $prefs = (array) ($user->preferences ?? []);
            $prefs['per_page'] = array_merge((array) ($prefs['per_page'] ?? []), [$list => $value]);
            $user->forceFill(['preferences' => $prefs])->saveQuietly();
        }

        return $value;
    }

    public static function rules(): array
    {
        return [
            'theme' => ['required', 'in:'.implode(',', array_keys(self::THEMES))],
            'accent' => ['required', 'in:'.implode(',', array_keys(self::ACCENTS))],
            'text_size' => ['required', 'in:'.implode(',', array_keys(self::TEXT_SIZES))],
            'density' => ['required', 'in:'.implode(',', array_keys(self::DENSITIES))],
            'home' => ['required', 'in:'.implode(',', array_keys(self::HOMES))],
        ];
    }

    public static function for(?User $user): array
    {
        $saved = (array) ($user?->preferences ?? []);
        $out = self::DEFAULTS;
        foreach (self::rules() as $k => $r) {
            $allowed = explode(',', substr($r[1], 3));
            if (isset($saved[$k]) && in_array($saved[$k], $allowed, true)) {
                $out[$k] = $saved[$k];
            }
        }

        return $out;
    }

    public static function homeUrl(?User $user): string
    {
        $route = self::HOMES[self::for($user)['home']][1];
        if (! \App\Support\Access::allows($user, \App\Support\Access::forRoute($route))) {
            $route = \App\Support\Access::homeRoute($user);
        }

        return route($route, absolute: false);
    }

    /** CSS das variáveis de destaque (claro + escuro). Valores vêm só da lista acima. */
    public static function accentCss(string $accent): string
    {
        [, $l, $d] = self::ACCENTS[$accent] ?? self::ACCENTS['laranja'];

        return ':root,[data-theme="light"]{--colors-primary-g100:'.$l[0].';--colors-primary-g500:'.$l[1].';--colors-primary-g600:'.$l[2].'}'
            .'[data-theme="dark"]{--colors-primary-g100:'.$d[0].';--colors-primary-g500:'.$d[1].';--colors-primary-g600:'.$d[2].';--brand-700:'.$d[2].'}';
    }
}

<?php

namespace App\Domains\Dashboard\Support;

use App\Models\User;

/**
 * Preferências do Dashboard por usuário: ordem, visibilidade, tipo de gráfico e período.
 * Sempre normalizadas: widgets desconhecidos somem, widgets novos entram no fim, tipos inválidos voltam ao padrão.
 */
class DashboardPreferences
{
    /** chave => [título, tipos de gráfico permitidos (o primeiro é o padrão), visível por padrão] */
    public const WIDGETS = [
        'cash_position' => ['Saldo bruto, terceiros e caixa próprio', [], true],
        'own_result' => ['Resultado próprio', [], true],
        'income_expense' => ['Entradas e saídas', ['bar', 'line', 'area'], true],
        'moving_averages' => ['Médias móveis do resultado', ['line', 'area', 'bar'], true],
        'invoices' => ['Faturas a vencer e vencidas', [], true],
        'receivables' => ['Valores a receber', [], true],
        'loans' => ['Empréstimos em aberto', [], true],
        'brokers' => ['Saldo com corretores', [], true],
        'writs_upcoming' => ['Requisitórios a receber nos próximos meses', ['bar', 'line', 'area'], true],
        'expenses_by_category' => ['Despesas por categoria', ['pie', 'bar'], true],
        'accounts' => ['Saldos por conta', [], true],
        'patrimony' => ['Patrimônio consolidado', [], true],
        'distribution' => ['Distribuição do patrimônio', ['pie', 'bar'], false],
        'investments' => ['Investimentos', [], true],
        'writs' => ['Requisitórios por etapa', ['bar', 'pie'], true],
        'partnerships' => ['Sociedade', [], true],
        'upcoming_invoices' => ['Faturas próximas (30 dias)', [], false],
    ];

    /** Grupos do painel (ordem fixa) e seus títulos. Personalizar reordena e oculta só dentro do grupo. */
    public const GROUPS = [
        'caixa' => 'Caixa',
        'graficos' => 'Gráficos',
        'requisitorios' => 'Requisitórios',
        'investimentos' => 'Investimentos e patrimônio',
        'contas' => 'Contas',
    ];

    /** chave => [grupo, tamanho] — s = 1 coluna, m = 2 colunas, l = largura toda (grade de 4 no computador). */
    public const LAYOUT = [
        'cash_position' => ['caixa', 'l'],
        'own_result' => ['caixa', 's'],
        'invoices' => ['caixa', 's'],
        'receivables' => ['caixa', 's'],
        'loans' => ['caixa', 's'],
        'upcoming_invoices' => ['caixa', 's'],
        'income_expense' => ['graficos', 'm'],
        'moving_averages' => ['graficos', 'm'],
        'expenses_by_category' => ['graficos', 'm'],
        'distribution' => ['graficos', 'm'],
        'writs_upcoming' => ['requisitorios', 'm'],
        'writs' => ['requisitorios', 'm'],
        'patrimony' => ['investimentos', 'l'],
        'investments' => ['investimentos', 'm'],
        'partnerships' => ['investimentos', 's'],
        'brokers' => ['investimentos', 's'],
        'accounts' => ['contas', 'l'],
    ];

    public const WRITS_MONTHS = [6, 12, 18, 24];

    public static function group(string $widget): string
    {
        return self::LAYOUT[$widget][0] ?? 'caixa';
    }

    public const SIZES = ['s' => 'P', 'm' => 'M', 'l' => 'G'];

    /** Tamanho do card: escolha do usuário (prefs['sizes']) ou o padrão do LAYOUT. */
    public static function size(string $widget, ?array $prefs = null): string
    {
        return $prefs['sizes'][$widget] ?? self::LAYOUT[$widget][1] ?? 's';
    }

    /** Widgets do grupo na ordem salva do usuário. */
    public static function inGroup(array $prefs, string $group): array
    {
        return array_values(array_filter($prefs['order'], fn ($k) => self::group($k) === $group));
    }

    public const CHART_LABELS = ['bar' => 'Barras', 'line' => 'Linha', 'area' => 'Área', 'pie' => 'Pizza'];

    public static function defaults(): array
    {
        return [
            'order' => array_keys(self::WIDGETS),
            'hidden' => array_keys(array_filter(self::WIDGETS, fn ($w) => ! $w[2])),
            'charts' => [],
            'period' => ['preset' => 'this_month', 'start' => null, 'end' => null],
            'writs_months' => 6,
            'sizes' => [],
        ];
    }

    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $defaults = self::defaults();
        $known = array_keys(self::WIDGETS);

        $order = array_values(array_unique(array_filter((array) ($raw['order'] ?? []), fn ($k) => is_string($k) && in_array($k, $known, true))));
        foreach ($known as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $hidden = array_key_exists('hidden', $raw)
            ? array_values(array_unique(array_filter((array) $raw['hidden'], fn ($k) => is_string($k) && in_array($k, $known, true))))
            : $defaults['hidden'];

        $charts = [];
        foreach ((array) ($raw['charts'] ?? []) as $key => $type) {
            if (isset(self::WIDGETS[$key]) && in_array($type, self::WIDGETS[$key][1], true)) {
                $charts[$key] = $type;
            }
        }

        $sizes = [];
        foreach ((array) ($raw['sizes'] ?? []) as $key => $size) {
            if (isset(self::WIDGETS[$key]) && isset(self::SIZES[$size])) {
                $sizes[$key] = $size;
            }
        }

        $period = (array) ($raw['period'] ?? []);
        $preset = array_key_exists((string) ($period['preset'] ?? ''), ReportPeriod::PRESETS) ? $period['preset'] : 'this_month';

        return [
            'order' => $order,
            'hidden' => $hidden,
            'charts' => $charts,
            'period' => ['preset' => $preset, 'start' => $period['start'] ?? null, 'end' => $period['end'] ?? null],
            'writs_months' => in_array($months = (int) ($raw['writs_months'] ?? 6), self::WRITS_MONTHS, true) ? $months : 6,
            'sizes' => $sizes,
        ];
    }

    public static function for(User $user): array
    {
        $raw = $user->dashboard_preferences;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return self::normalize($raw);
    }

    public static function save(User $user, array $prefs): array
    {
        $prefs = self::normalize($prefs);
        $user->forceFill(['dashboard_preferences' => $prefs])->save();

        return $prefs;
    }

    public static function chartType(array $prefs, string $widget): ?string
    {
        $allowed = self::WIDGETS[$widget][1] ?? [];

        return $prefs['charts'][$widget] ?? ($allowed[0] ?? null);
    }

    /** Arrastar e soltar: coloca $widget na posição de $target (antes dele se veio de baixo, depois se veio de cima). */
    public static function moveTo(array $prefs, string $widget, string $target): array
    {
        $order = $prefs['order'];
        $from = array_search($widget, $order, true);
        $to = array_search($target, $order, true);
        if ($from === false || $to === false || $from === $to) {
            return $prefs;
        }
        array_splice($order, $from, 1);
        array_splice($order, $to, 0, [$widget]);
        $prefs['order'] = $order;

        return $prefs;
    }

    public static function move(array $prefs, string $widget, int $delta): array
    {
        $order = $prefs['order'];
        $i = array_search($widget, $order, true);
        $j = $i === false ? false : $i + $delta;
        // pula cards ocultos: ↑ ↓ troca com o vizinho visível
        while ($j !== false && $j >= 0 && $j < count($order) && in_array($order[$j], $prefs['hidden'] ?? [], true)) {
            $j += $delta;
        }
        if ($i === false || $j < 0 || $j >= count($order)) {
            return $prefs;
        }
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        $prefs['order'] = $order;

        return $prefs;
    }
}

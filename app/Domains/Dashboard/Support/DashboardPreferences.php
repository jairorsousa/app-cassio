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

    public const CHART_LABELS = ['bar' => 'Barras', 'line' => 'Linha', 'area' => 'Área', 'pie' => 'Pizza'];

    public static function defaults(): array
    {
        return [
            'order' => array_keys(self::WIDGETS),
            'hidden' => array_keys(array_filter(self::WIDGETS, fn ($w) => ! $w[2])),
            'charts' => [],
            'period' => ['preset' => 'this_month', 'start' => null, 'end' => null],
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

        $period = (array) ($raw['period'] ?? []);
        $preset = array_key_exists((string) ($period['preset'] ?? ''), ReportPeriod::PRESETS) ? $period['preset'] : 'this_month';

        return [
            'order' => $order,
            'hidden' => $hidden,
            'charts' => $charts,
            'period' => ['preset' => $preset, 'start' => $period['start'] ?? null, 'end' => $period['end'] ?? null],
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

    public static function move(array $prefs, string $widget, int $delta): array
    {
        $order = $prefs['order'];
        $i = array_search($widget, $order, true);
        $j = $i === false ? false : $i + $delta;
        if ($i === false || $j < 0 || $j >= count($order)) {
            return $prefs;
        }
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        $prefs['order'] = $order;

        return $prefs;
    }
}

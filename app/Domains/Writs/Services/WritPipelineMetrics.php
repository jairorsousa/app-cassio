<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Métricas do painel de Requisitórios (só leitura). Agrupa e nomeia números
 * já existentes, sem gravar nada no banco.
 */
class WritPipelineMetrics
{
    public const PERIOD_12M = '12m';

    private const MONTHS_SHORT = [1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'];

    private Carbon $asOf;

    private string $timezone;

    public function __construct(?Carbon $asOf = null)
    {
        $this->timezone = (string) config('app.business_timezone', 'America/Fortaleza');
        $this->asOf = ($asOf ?? Carbon::now($this->timezone))->copy()->timezone($this->timezone)->startOfDay();
    }

    /**
     * @param  Collection<int, Writ>  $writs
     * @return array<string, mixed>
     */
    public function forCollection(Collection $writs, string $period = self::PERIOD_12M): array
    {
        $acquiredOpen = $writs->whereIn('stage', Writ::ACQUIRED_OPEN_STAGES)->values();
        $finalized = $writs->where('stage', 'finalized')->values();
        $acquiredAll = $writs->whereIn('stage', Writ::ACQUIRED_STAGES)->values();
        $pipeline = $writs->whereIn('stage', ['monitoring', 'negotiation', 'pending', 'signature'])->values();
        $lost = $writs->where('stage', 'lost')->values();

        $openCost = $this->money($acquiredOpen->sum(fn (Writ $writ) => $writ->totalCost()));
        $expectedReceipt = $this->money($acquiredOpen->sum(fn (Writ $writ) => (float) $writ->estimated_receipt_amount));
        $expectedProfit = $this->money($expectedReceipt - $openCost);
        $expectedProfitPct = $openCost > 0 ? round($expectedProfit / $openCost * 100, 2) : null;
        $openWithoutCost = $acquiredOpen->filter(fn (Writ $writ) => $writ->totalCost() <= 0)->count();

        $received = $this->money($finalized->sum(fn (Writ $writ) => (float) $writ->actual_receipt_amount));
        $finalizedCost = $this->money($finalized->sum(fn (Writ $writ) => $writ->totalCost()));
        $realizedProfit = $this->money($received - $finalizedCost);
        $realizedProfitPct = $finalizedCost > 0 ? round($realizedProfit / $finalizedCost * 100, 2) : null;
        $totalInvested = $this->money($openCost + $finalizedCost);

        $faceAcquired = $this->money($acquiredAll->sum('face_value'));
        $negotiatedAcquired = $this->money($acquiredAll->sum(fn (Writ $writ) => (float) ($writ->negotiated_amount ?: $writ->face_value)));

        $overdue = $acquiredOpen->filter(fn (Writ $writ) => ($writ->deadlineStatus($this->asOf)['key'] ?? null) === 'overdue')->values();
        $dueSoon = $acquiredOpen->filter(fn (Writ $writ) => ($writ->deadlineStatus($this->asOf)['key'] ?? null) === 'due_soon')->values();
        $onTime = $acquiredOpen->filter(fn (Writ $writ) => ($writ->deadlineStatus($this->asOf)['key'] ?? null) === 'on_time')->values();
        $openWithoutDeadline = $acquiredOpen->filter(fn (Writ $writ) => $writ->deadlineStatus($this->asOf) === null)->values();

        $stageCounts = [];
        foreach (Writ::STAGES as $stage) {
            $stageCounts[$stage] = [
                'key' => $stage,
                'label' => Writ::STAGE_LABELS[$stage],
                'count' => $writs->where('stage', $stage)->count(),
            ];
        }

        $byReceiptMonth = $this->groupFinalizedByMonth($finalized);
        $yearSummaries = $this->yearSummaries($byReceiptMonth);
        $availableYears = collect($yearSummaries)->pluck('year')->map(fn ($year) => (int) $year)->all();
        $currentYear = (int) $this->asOf->year;
        if (! in_array($currentYear, $availableYears, true)) {
            $availableYears[] = $currentYear;
            rsort($availableYears);
        }

        $period = $this->normalizePeriod($period, $availableYears);
        $chart = $this->chartForPeriod($byReceiptMonth, $period);

        $anomalies = $this->anomalies($writs, $finalized, $acquiredOpen);

        return [
            'as_of' => $this->asOf->toDateString(),
            'period' => $period,
            'available_years' => $availableYears,
            'open' => [
                'count' => $acquiredOpen->count(),
                'cost' => $openCost,
                'expected_receipt' => $expectedReceipt,
                'expected_profit' => $expectedProfit,
                'expected_profit_pct' => $expectedProfitPct,
                'without_cost' => $openWithoutCost,
                'overdue_count' => $overdue->count(),
                'overdue_expected_receipt' => $this->money($overdue->sum(fn (Writ $writ) => (float) $writ->estimated_receipt_amount)),
                'overdue_cost' => $this->money($overdue->sum(fn (Writ $writ) => $writ->totalCost())),
                'due_soon_count' => $dueSoon->count(),
                'on_time_count' => $onTime->count(),
                'without_deadline' => $openWithoutDeadline->count(),
                'by_stage' => [
                    'paid' => $acquiredOpen->where('stage', 'paid')->count(),
                    'petitioning' => $acquiredOpen->where('stage', 'petitioning')->count(),
                    'awaiting_receipt' => $acquiredOpen->where('stage', 'awaiting_receipt')->count(),
                ],
            ],
            'received' => [
                'count' => $finalized->count(),
                'amount' => $received,
                'cost' => $finalizedCost,
                'profit' => $realizedProfit,
                'profit_pct' => $realizedProfitPct,
                'without_date' => $finalized->filter(fn (Writ $writ) => ! $writ->finalized_at)->count(),
            ],
            'invested' => [
                'total' => $totalInvested,
                'acquired_count' => $acquiredAll->count(),
                'face' => $faceAcquired,
                'negotiated' => $negotiatedAcquired,
            ],
            'situation' => [
                'pipeline_count' => $pipeline->count(),
                'lost_count' => $lost->count(),
                'stages' => $stageCounts,
            ],
            'chart' => $chart,
            'years' => $yearSummaries,
            'averages' => $this->averages($finalized, $byReceiptMonth, $yearSummaries, $currentYear),
            'upcoming' => $this->upcoming($acquiredOpen, $overdue),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * @param  Collection<int, Writ>  $finalized
     * @return array<string, array{key: string, year: int, month: int, label: string, count: int, received: float, cost: float, profit: float}>
     */
    private function groupFinalizedByMonth(Collection $finalized): array
    {
        $groups = [];
        foreach ($finalized as $writ) {
            if (! $writ->finalized_at) {
                continue;
            }
            $date = Carbon::parse($writ->finalized_at)->timezone($this->timezone)->startOfDay();
            $key = $date->format('Y-m');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('n'),
                    'label' => self::MONTHS_SHORT[(int) $date->format('n')].'/'.$date->format('y'),
                    'count' => 0,
                    'received' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $receipt = (float) $writ->actual_receipt_amount;
            $cost = $writ->totalCost();
            $groups[$key]['count']++;
            $groups[$key]['received'] = $this->money($groups[$key]['received'] + $receipt);
            $groups[$key]['cost'] = $this->money($groups[$key]['cost'] + $cost);
            $groups[$key]['profit'] = $this->money($groups[$key]['profit'] + ($receipt - $cost));
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @param  array<string, array{year: int, count: int, received: float, cost: float, profit: float}>  $byMonth
     * @return list<array{year: int, count: int, received: float, cost: float, profit: float, profit_pct: ?float}>
     */
    private function yearSummaries(array $byMonth): array
    {
        $years = [];
        foreach ($byMonth as $row) {
            $year = $row['year'];
            if (! isset($years[$year])) {
                $years[$year] = ['year' => $year, 'count' => 0, 'received' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
            }
            $years[$year]['count'] += $row['count'];
            $years[$year]['received'] = $this->money($years[$year]['received'] + $row['received']);
            $years[$year]['cost'] = $this->money($years[$year]['cost'] + $row['cost']);
            $years[$year]['profit'] = $this->money($years[$year]['profit'] + $row['profit']);
        }
        krsort($years);

        return array_values(array_map(function (array $row) {
            $row['profit_pct'] = $row['cost'] > 0 ? round($row['profit'] / $row['cost'] * 100, 2) : null;

            return $row;
        }, $years));
    }

    /**
     * @param  array<string, array{key: string, label: string, count: int, received: float, cost: float, profit: float}>  $byMonth
     * @return array{period: string, title: string, labels: list<string>, months: list<array>, datasets: list<array{name: string, color: string, values: list<float>}>}
     */
    private function chartForPeriod(array $byMonth, string $period): array
    {
        if ($period === self::PERIOD_12M) {
            $start = $this->asOf->copy()->startOfMonth()->subMonths(11);
            $title = 'Últimos 12 meses';
            $keys = [];
            for ($i = 0; $i < 12; $i++) {
                $keys[] = $start->copy()->addMonths($i)->format('Y-m');
            }
        } else {
            $year = (int) $period;
            $title = (string) $year;
            $keys = [];
            for ($month = 1; $month <= 12; $month++) {
                $keys[] = sprintf('%04d-%02d', $year, $month);
            }
        }

        $months = [];
        foreach ($keys as $key) {
            $row = $byMonth[$key] ?? null;
            $parts = explode('-', $key);
            $months[] = [
                'key' => $key,
                'label' => self::MONTHS_SHORT[(int) $parts[1]].'/'.substr($parts[0], 2),
                'count' => $row['count'] ?? 0,
                'received' => $row['received'] ?? 0.0,
                'cost' => $row['cost'] ?? 0.0,
                'profit' => $row['profit'] ?? 0.0,
            ];
        }

        return [
            'period' => $period,
            'title' => $title,
            'labels' => array_column($months, 'label'),
            'months' => $months,
            'datasets' => [
                ['name' => 'Recebido', 'color' => '#16a34a', 'values' => array_column($months, 'received')],
                ['name' => 'Lucro', 'color' => '#ff6f00', 'values' => array_column($months, 'profit')],
            ],
        ];
    }

    /**
     * @param  Collection<int, Writ>  $finalized
     * @param  array<string, array{received: float, profit: float, count: int}>  $byMonth
     * @param  list<array{year: int, count: int, received: float, cost: float, profit: float}>  $yearSummaries
     * @return array<string, mixed>
     */
    private function averages(Collection $finalized, array $byMonth, array $yearSummaries, int $currentYear): array
    {
        $start12 = $this->asOf->copy()->startOfMonth()->subMonths(11);
        $end12 = $this->asOf->copy()->endOfMonth();
        $in12 = $finalized->filter(function (Writ $writ) use ($start12, $end12) {
            if (! $writ->finalized_at) {
                return false;
            }
            $date = Carbon::parse($writ->finalized_at)->timezone($this->timezone)->startOfDay();

            return $date->betweenIncluded($start12, $end12);
        });
        $received12 = $this->money($in12->sum(fn (Writ $writ) => (float) $writ->actual_receipt_amount));
        $profit12 = $this->money($in12->sum(fn (Writ $writ) => (float) $writ->actual_receipt_amount - $writ->totalCost()));

        $yearRow = collect($yearSummaries)->firstWhere('year', $currentYear);
        $receivedYear = $yearRow['received'] ?? 0.0;
        $profitYear = $yearRow['profit'] ?? 0.0;
        $yearCount = $yearRow['count'] ?? 0;
        $monthsElapsedYear = max(1, (int) $this->asOf->month);

        $withDates = $finalized->filter(fn (Writ $writ) => $writ->paid_at && $writ->finalized_at);
        $daysList = $withDates->map(function (Writ $writ) {
            return (int) Carbon::parse($writ->paid_at)->startOfDay()->diffInDays(Carbon::parse($writ->finalized_at)->startOfDay());
        });
        $avgDays = $daysList->count() > 0 ? (int) round($daysList->avg()) : null;
        $avgMonths = $avgDays !== null ? round($avgDays / 30.4375, 1) : null;
        $avgProfitPerWrit = $finalized->count() > 0
            ? $this->money($finalized->sum(fn (Writ $writ) => (float) $writ->actual_receipt_amount - $writ->totalCost()) / $finalized->count())
            : null;

        return [
            'year' => $currentYear,
            'months_elapsed_year' => $monthsElapsedYear,
            'monthly_received_year' => $this->money($receivedYear / $monthsElapsedYear),
            'monthly_profit_year' => $this->money($profitYear / $monthsElapsedYear),
            'year_count' => $yearCount,
            'last12_start' => $start12->toDateString(),
            'last12_end' => $end12->toDateString(),
            'monthly_received_12m' => $this->money($received12 / 12),
            'monthly_profit_12m' => $this->money($profit12 / 12),
            'last12_count' => $in12->count(),
            'avg_days_to_receipt' => $avgDays,
            'avg_months_to_receipt' => $avgMonths,
            'timed_count' => $withDates->count(),
            'avg_profit_per_writ' => $avgProfitPerWrit,
            'monthly_equivalent' => $this->equivalentMonthlyRate($finalized),
        ];
    }

    /**
     * Taxa mensal equivalente do conjunto, só com operações de pelo menos 30 dias.
     * Média simples das taxas individuais não é segura (crédito que volta em 1 dia dispara o %).
     *
     * @param  Collection<int, Writ>  $finalized
     * @return array{percent: float, count: int, avg_months: float, excluded_short: int}|null
     */
    private function equivalentMonthlyRate(Collection $finalized): ?array
    {
        $eligible = $finalized->filter(function (Writ $writ) {
            if (! $writ->paid_at || ! $writ->finalized_at || $writ->totalCost() <= 0) {
                return false;
            }
            $days = (int) Carbon::parse($writ->paid_at)->startOfDay()->diffInDays(Carbon::parse($writ->finalized_at)->startOfDay());

            return $days >= 30;
        });

        $excluded = $finalized->filter(fn (Writ $writ) => $writ->paid_at && $writ->finalized_at)->count() - $eligible->count();

        if ($eligible->count() < 3) {
            return null;
        }

        $cost = $eligible->sum(fn (Writ $writ) => $writ->totalCost());
        $received = $eligible->sum(fn (Writ $writ) => (float) $writ->actual_receipt_amount);
        if ($cost <= 0 || $received <= 0) {
            return null;
        }

        $weightedDays = $eligible->sum(function (Writ $writ) {
            $days = (int) Carbon::parse($writ->paid_at)->startOfDay()->diffInDays(Carbon::parse($writ->finalized_at)->startOfDay());

            return $days * $writ->totalCost();
        });
        $avgMonths = ($weightedDays / $cost) / 30.4375;
        if ($avgMonths < 1) {
            return null;
        }

        $monthly = (pow($received / $cost, 1 / $avgMonths) - 1) * 100;

        return [
            'percent' => round($monthly, 2),
            'count' => $eligible->count(),
            'avg_months' => round($avgMonths, 1),
            'excluded_short' => $excluded,
        ];
    }

    /**
     * @param  Collection<int, Writ>  $acquiredOpen
     * @param  Collection<int, Writ>  $overdue
     * @return array{overdue: array, months: list<array>}
     */
    private function upcoming(Collection $acquiredOpen, Collection $overdue): array
    {
        $onTime = $acquiredOpen->reject(fn (Writ $writ) => ($writ->deadlineStatus($this->asOf)['key'] ?? null) === 'overdue');
        $byMonth = [];
        foreach ($onTime as $writ) {
            $deadline = $writ->estimatedReceiptDeadline();
            $key = $deadline ? $deadline->copy()->timezone($this->timezone)->format('Y-m') : 'sem-previsao';
            if (! isset($byMonth[$key])) {
                $label = $key === 'sem-previsao'
                    ? 'Sem previsão'
                    : self::MONTHS_SHORT[(int) substr($key, 5, 2)].'/'.substr($key, 2, 2);
                $byMonth[$key] = ['key' => $key, 'label' => $label, 'count' => 0, 'expected_receipt' => 0.0, 'cost' => 0.0];
            }
            $byMonth[$key]['count']++;
            $byMonth[$key]['expected_receipt'] = $this->money($byMonth[$key]['expected_receipt'] + (float) $writ->estimated_receipt_amount);
            $byMonth[$key]['cost'] = $this->money($byMonth[$key]['cost'] + $writ->totalCost());
        }
        uksort($byMonth, function ($a, $b) {
            if ($a === 'sem-previsao') {
                return 1;
            }
            if ($b === 'sem-previsao') {
                return -1;
            }

            return $a <=> $b;
        });

        return [
            'overdue' => [
                'count' => $overdue->count(),
                'expected_receipt' => $this->money($overdue->sum(fn (Writ $writ) => (float) $writ->estimated_receipt_amount)),
                'cost' => $this->money($overdue->sum(fn (Writ $writ) => $writ->totalCost())),
            ],
            'months' => array_values($byMonth),
        ];
    }

    /**
     * Só para o relatório interno: nada é gravado nem exibido como correção.
     *
     * @param  Collection<int, Writ>  $writs
     * @param  Collection<int, Writ>  $finalized
     * @param  Collection<int, Writ>  $acquiredOpen
     * @return list<array{id: int, issue: string}>
     */
    private function anomalies(Collection $writs, Collection $finalized, Collection $acquiredOpen): array
    {
        $items = [];
        foreach ($finalized as $writ) {
            if ($writ->actual_receipt_amount === null) {
                $items[] = ['id' => $writ->id, 'issue' => 'recebido_sem_valor'];
            }
            if (! $writ->finalized_at) {
                $items[] = ['id' => $writ->id, 'issue' => 'recebido_sem_data'];
            }
            if ($writ->awaiting_receipt_at && $writ->finalized_at && Carbon::parse($writ->awaiting_receipt_at)->gt($writ->finalized_at)) {
                $items[] = ['id' => $writ->id, 'issue' => 'data_de_aguardando_depois_do_recebimento'];
            }
        }
        foreach ($acquiredOpen as $writ) {
            if ($writ->estimatedProfit() < 0) {
                $items[] = ['id' => $writ->id, 'issue' => 'lucro_esperado_negativo'];
            }
            if ((float) ($writ->estimated_receipt_amount ?? 0) <= 0) {
                $items[] = ['id' => $writ->id, 'issue' => 'em_aberto_sem_previsao_de_recebimento'];
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $availableYears
     */
    private function normalizePeriod(string $period, array $availableYears): string
    {
        if ($period === self::PERIOD_12M) {
            return self::PERIOD_12M;
        }
        if (preg_match('/^\d{4}$/', $period) && in_array((int) $period, $availableYears, true)) {
            return $period;
        }

        return self::PERIOD_12M;
    }

    private function money(float|int $value): float
    {
        return round((float) $value, 2);
    }
}

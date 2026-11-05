<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

class BrokerReportService
{
    public function generate(
        string $month,
        int $year,
        ?int $brokerId = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        [$rangeStart, $rangeEnd] = $this->dateRange($month, $year, $startDate, $endDate);

        $queryCommissions = $this->applyFilters(
            BrokerCommission::query()->whereHas('broker', fn ($q) => $q->withoutTestContacts()),
            'reference_date',
            $brokerId,
            $rangeStart,
            $rangeEnd,
        );
        $queryAdvances = $this->applyFilters(
            BrokerAdvance::query()->personal()->whereHas('broker', fn ($q) => $q->withoutTestContacts()),
            'date',
            $brokerId,
            $rangeStart,
            $rangeEnd,
        );

        $totalCommissions = (clone $queryCommissions)->sum('commission_amount');
        $totalPendingCommissions = (clone $queryCommissions)
            ->where('status', 'pending')
            ->sum('commission_amount');
        $totalAdvances = (clone $queryAdvances)->sum('amount');

        $paidCommissions = (clone $queryCommissions)
            ->whereIn('status', ['paid', 'partially_paid'])
            ->with('broker', 'caseType')
            ->orderByDesc('reference_date')
            ->get();

        $advances = (clone $queryAdvances)
            ->with('broker')
            ->orderByDesc('date')
            ->get();
        $openAdvancesBalance = $advances->sum(fn ($advance) => $advance->remainingBalance());

        $brokers = Broker::query()
            ->withoutTestContacts()
            ->when($brokerId, fn (Builder $query) => $query->whereKey($brokerId))
            ->with(['commissions' => fn ($query) => $this->applyFilters(
                $query,
                'reference_date',
                $brokerId,
                $rangeStart,
                $rangeEnd,
            )])
            ->orderBy('name')
            ->get()
            ->filter(fn (Broker $broker) => $broker->commissions->isNotEmpty())
            ->map(function (Broker $broker) {
                $broker->setAttribute(
                    'commissions_total_period',
                    round((float) $broker->commissions->sum('commission_amount'), 2),
                );

                return $broker;
            });

        $selectedBroker = $brokerId ? Broker::withoutTestContacts()->find($brokerId) : null;

        $annualByBroker = Broker::query()
            ->withoutTestContacts()
            ->when($brokerId, fn (Builder $query) => $query->whereKey($brokerId))
            ->with(['commissions' => function ($query) use ($year) {
                $query->whereYear('reference_date', $year);
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (Broker $broker) => [
                'broker_id' => $broker->id,
                'broker_name' => $broker->name,
                'total' => round((float) $broker->commissions->sum('commission_amount'), 2),
                'count' => $broker->commissions->count(),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();

        return compact(
            'totalCommissions',
            'totalPendingCommissions',
            'totalAdvances',
            'openAdvancesBalance',
            'paidCommissions',
            'advances',
            'brokers',
            'selectedBroker',
            'rangeStart',
            'rangeEnd',
            'annualByBroker',
            'year',
        );
    }

    private function dateRange(
        string $month,
        int $year,
        ?string $startDate,
        ?string $endDate,
    ): array {
        if ($startDate || $endDate) {
            return [$startDate, $endDate];
        }

        if ($month === 'all') {
            return [
                Carbon::create($year, 1, 1)->startOfYear()->toDateString(),
                Carbon::create($year, 12, 1)->endOfYear()->toDateString(),
            ];
        }

        $date = Carbon::create($year, (int) $month, 1);

        return [
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function applyFilters(
        Builder|Relation $query,
        string $dateColumn,
        ?int $brokerId,
        ?string $startDate,
        ?string $endDate,
    ): Builder|Relation {
        return $query
            ->when($startDate, fn ($query) => $query->whereDate($dateColumn, '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate($dateColumn, '<=', $endDate))
            ->when($brokerId, fn ($query) => $query->where('broker_id', $brokerId));
    }
}

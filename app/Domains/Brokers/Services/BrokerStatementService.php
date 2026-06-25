<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BrokerStatementService
{
    /**
     * @return array<string, float>
     */
    public function summary(Broker $broker, ?string $startDate = null, ?string $endDate = null): array
    {
        $commissionIds = $broker->commissions()->pluck('id');
        $advanceIds = $broker->advances()->pluck('id');

        $advancedPeriod = (float) $this->applyPeriod(
            BrokerAdvance::where('broker_id', $broker->id),
            'date',
            $startDate,
            $endDate,
        )->sum('amount');

        $commissionsPeriod = (float) $this->applyPeriod(
            BrokerCommission::where('broker_id', $broker->id),
            'reference_date',
            $startDate,
            $endDate,
        )->sum('commission_amount');

        $settledPeriod = (float) $this->applyPeriod(
            BrokerCommissionSettlement::whereIn('commission_id', $commissionIds),
            'settled_at',
            $startDate,
            $endDate,
        )->sum('amount_offset');

        $repassedPeriod = (float) $this->applyPeriod(
            BrokerCommissionPayment::where('broker_id', $broker->id),
            'paid_at',
            $startDate,
            $endDate,
        )->sum('amount');

        $advancePending = max(
            (float) $broker->advances()->sum('amount')
                - (float) BrokerCommissionSettlement::whereIn('advance_id', $advanceIds)->sum('amount_offset'),
            0,
        );

        $commissionPending = (float) $broker->commissions()
            ->with('settlements', 'payments')
            ->get()
            ->sum(fn (BrokerCommission $commission) => $commission->remainingAmount());

        return [
            'advanced_period' => round($advancedPeriod, 2),
            'advance_pending' => round($advancePending, 2),
            'commissions_period' => round($commissionsPeriod, 2),
            'settled_period' => round($settledPeriod, 2),
            'repassed_period' => round($repassedPeriod, 2),
            'commission_pending' => round($commissionPending, 2),
            'cash_out_period' => round($advancedPeriod + $repassedPeriod, 2),
        ];
    }

    public function entries(Broker $broker, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $advances = $this->applyPeriod(
            $broker->advances()->with('bankAccount'),
            'date',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerAdvance $advance) => [
            'date' => $advance->date,
            'type' => 'Adiantamento',
            'description' => $advance->payment_method ?: 'Adiantamento ao corretor',
            'amount' => (float) $advance->amount,
            'tone' => 'down',
            'icon' => 'account_balance_wallet',
        ]);

        $commissions = $this->applyPeriod(
            $broker->commissions()->with('caseType'),
            'reference_date',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerCommission $commission) => [
            'date' => $commission->reference_date,
            'type' => 'Comissão gerada',
            'description' => $commission->caseType->name,
            'amount' => (float) $commission->commission_amount,
            'tone' => 'neutral',
            'icon' => 'percent',
        ]);

        $commissionIds = $broker->commissions()->pluck('id');

        $settlements = $this->applyPeriod(
            BrokerCommissionSettlement::with('advance', 'commission.caseType')
                ->whereIn('commission_id', $commissionIds),
            'settled_at',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerCommissionSettlement $settlement) => [
            'date' => Carbon::parse($settlement->settled_at),
            'type' => 'Compensação',
            'description' => 'Abatido em '.$settlement->commission->caseType->name,
            'amount' => (float) $settlement->amount_offset,
            'tone' => 'up',
            'icon' => 'sync',
        ]);

        $payments = $this->applyPeriod(
            BrokerCommissionPayment::with('commission.caseType')
                ->where('broker_id', $broker->id),
            'paid_at',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerCommissionPayment $payment) => [
            'date' => $payment->paid_at,
            'type' => 'Repasse',
            'description' => $payment->commission->caseType->name,
            'amount' => (float) $payment->amount,
            'tone' => 'down',
            'icon' => 'payments',
        ]);

        return $advances
            ->concat($commissions)
            ->concat($settlements)
            ->concat($payments)
            ->sortByDesc(fn (array $entry) => $entry['date']->format('Y-m-d H:i:s'))
            ->values();
    }

    private function applyPeriod($query, string $column, ?string $startDate, ?string $endDate)
    {
        if ($startDate) {
            $query->whereDate($column, '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate($column, '<=', $endDate);
        }

        return $query;
    }
}

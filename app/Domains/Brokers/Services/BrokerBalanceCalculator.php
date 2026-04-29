<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;

class BrokerBalanceCalculator
{
    /**
     * Calcula o saldo de adiantamentos de um corretor.
     *
     * @return array{
     *   total_advanced: float,
     *   total_settled: float,
     *   balance: float,
     * }
     */
    public function forBroker(Broker $broker): array
    {
        $totalAdvanced = (float) $broker->advances()->sum('amount');

        $advanceIds = $broker->advances()->pluck('id');
        $totalSettled = (float) BrokerCommissionSettlement::whereIn('advance_id', $advanceIds)
            ->sum('amount_offset');

        return [
            'total_advanced' => round($totalAdvanced, 2),
            'total_settled' => round($totalSettled, 2),
            'balance' => round($totalAdvanced - $totalSettled, 2),
        ];
    }

    /**
     * Total de comissões pagas/pendentes de um corretor.
     *
     * @return array{
     *   total_commissions: float,
     *   total_pending: float,
     *   total_paid: float,
     * }
     */
    public function commissionsForBroker(Broker $broker): array
    {
        $commissions = $broker->commissions;

        $total = (float) $commissions->sum('commission_amount');
        $pending = (float) $commissions->where('status', 'pending')->sum('commission_amount');
        $paid = (float) $commissions->whereIn('status', ['paid', 'partially_paid'])->sum('commission_amount');

        return [
            'total_commissions' => round($total, 2),
            'total_pending' => round($pending, 2),
            'total_paid' => round($paid, 2),
        ];
    }
}

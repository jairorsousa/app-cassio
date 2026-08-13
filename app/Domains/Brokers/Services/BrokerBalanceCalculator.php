<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
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
        $commissions = $broker->commissions()->with('payments', 'settlements')->get();

        $total = (float) $commissions->sum('commission_amount');
        $pending = (float) $commissions->sum(fn ($commission) => $commission->remainingAmount());
        $paid = (float) $commissions->sum(fn ($commission) => $commission->paidAmount());

        return [
            'total_commissions' => round($total, 2),
            'total_pending' => round($pending, 2),
            'total_paid' => round($paid, 2),
        ];
    }

    /**
     * Saldos em aberto por corretor, no mesmo critério da tela de detalhes.
     *
     * @param  list<int>  $brokerIds
     * @return array<int, array{advance_pending: float, commission_pending: float}>
     */
    public function pendingBalancesFor(array $brokerIds): array
    {
        $brokerIds = array_values(array_unique(array_filter($brokerIds)));

        if ($brokerIds === []) {
            return [];
        }

        $advances = BrokerAdvance::query()
            ->whereIn('broker_id', $brokerIds)
            ->selectRaw('broker_id, SUM(amount) as total')
            ->groupBy('broker_id')
            ->pluck('total', 'broker_id');

        $settledAdvances = BrokerCommissionSettlement::query()
            ->join('broker_advances', 'broker_advances.id', '=', 'broker_commission_settlements.advance_id')
            ->whereIn('broker_advances.broker_id', $brokerIds)
            ->selectRaw('broker_advances.broker_id as broker_id, SUM(broker_commission_settlements.amount_offset) as total')
            ->groupBy('broker_advances.broker_id')
            ->pluck('total', 'broker_id');

        $commissions = BrokerCommission::query()
            ->whereIn('broker_id', $brokerIds)
            ->selectRaw('broker_id, SUM(commission_amount) as total')
            ->groupBy('broker_id')
            ->pluck('total', 'broker_id');

        $settledCommissions = BrokerCommissionSettlement::query()
            ->join('broker_commissions', 'broker_commissions.id', '=', 'broker_commission_settlements.commission_id')
            ->whereIn('broker_commissions.broker_id', $brokerIds)
            ->selectRaw('broker_commissions.broker_id as broker_id, SUM(broker_commission_settlements.amount_offset) as total')
            ->groupBy('broker_commissions.broker_id')
            ->pluck('total', 'broker_id');

        $payments = BrokerCommissionPayment::query()
            ->whereIn('broker_id', $brokerIds)
            ->selectRaw('broker_id, SUM(amount) as total')
            ->groupBy('broker_id')
            ->pluck('total', 'broker_id');

        $balances = [];

        foreach ($brokerIds as $brokerId) {
            $advancePending = max(
                (float) ($advances[$brokerId] ?? 0) - (float) ($settledAdvances[$brokerId] ?? 0),
                0,
            );
            $commissionPending = max(
                (float) ($commissions[$brokerId] ?? 0)
                    - (float) ($settledCommissions[$brokerId] ?? 0)
                    - (float) ($payments[$brokerId] ?? 0),
                0,
            );

            $balances[(int) $brokerId] = [
                'advance_pending' => round($advancePending, 2),
                'commission_pending' => round($commissionPending, 2),
            ];
        }

        return $balances;
    }
}

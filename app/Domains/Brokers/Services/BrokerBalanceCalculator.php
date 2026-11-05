<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\BrokerProcessExpense;

class BrokerBalanceCalculator
{
    /**
     * @return array{
     *   total_advanced: float,
     *   total_settled: float,
     *   balance: float,
     * }
     */
    public function forBroker(Broker $broker): array
    {
        $totalAdvanced = (float) $broker->advances()->personal()->sum('amount');

        $advanceIds = $broker->advances()->personal()->pluck('id');
        $totalSettled = (float) BrokerCommissionSettlement::whereIn('advance_id', $advanceIds)
            ->sum('amount_offset');

        return [
            'total_advanced' => round($totalAdvanced, 2),
            'total_settled' => round($totalSettled, 2),
            'balance' => round($totalAdvanced - $totalSettled, 2),
        ];
    }

    /**
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
     * Fórmulas da Etapa 3.
     *
     * @return array{
     *   saldo_adiantado_pessoal: float,
     *   comissao_gerada: float,
     *   comissao_compensada: float,
     *   comissao_repassada: float,
     *   comissao_a_pagar: float,
     *   saldo_devedor: float,
     *   despesa_processo_em_aberto: float,
     * }
     */
    public function ledgerForBroker(Broker $broker): array
    {
        $advanceIds = $broker->advances()->personal()->pluck('id');
        $commissionIds = $broker->commissions()->pluck('id');

        $saldoAdiantado = round(
            (float) $broker->advances()->personal()->sum('amount')
            - (float) BrokerCommissionSettlement::whereIn('advance_id', $advanceIds)->sum('amount_offset'),
            2,
        );

        $comissaoGerada = round((float) $broker->commissions()->sum('commission_amount'), 2);
        $comissaoCompensada = round((float) BrokerCommissionSettlement::whereIn('commission_id', $commissionIds)->sum('amount_offset'), 2);
        $comissaoRepassada = round((float) BrokerCommissionPayment::where('broker_id', $broker->id)->sum('amount'), 2);
        $comissaoAPagar = round(max($comissaoGerada - $comissaoCompensada - $comissaoRepassada, 0), 2);
        $saldoDevedor = round($saldoAdiantado - $comissaoAPagar, 2);

        $despesaAberto = round(
            $broker->processExpenses()->with('recoveries')->get()
                ->sum(fn (BrokerProcessExpense $expense) => $expense->remainingCents() / 100),
            2,
        );

        return [
            'saldo_adiantado_pessoal' => $saldoAdiantado,
            'comissao_gerada' => $comissaoGerada,
            'comissao_compensada' => $comissaoCompensada,
            'comissao_repassada' => $comissaoRepassada,
            'comissao_a_pagar' => $comissaoAPagar,
            'saldo_devedor' => $saldoDevedor,
            'despesa_processo_em_aberto' => $despesaAberto,
        ];
    }

    /**
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
            ->personal()
            ->whereIn('broker_id', $brokerIds)
            ->selectRaw('broker_id, SUM(amount) as total')
            ->groupBy('broker_id')
            ->pluck('total', 'broker_id');

        $settledAdvances = BrokerCommissionSettlement::query()
            ->join('broker_advances', 'broker_advances.id', '=', 'broker_commission_settlements.advance_id')
            ->where('broker_advances.kind', BrokerAdvance::KIND_PERSONAL)
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

        $paidCommissions = BrokerCommissionPayment::query()
            ->whereIn('broker_id', $brokerIds)
            ->selectRaw('broker_id, SUM(amount) as total')
            ->groupBy('broker_id')
            ->pluck('total', 'broker_id');

        $result = [];
        foreach ($brokerIds as $id) {
            $advanced = (float) ($advances[$id] ?? 0);
            $settledAdv = (float) ($settledAdvances[$id] ?? 0);
            $commissionTotal = (float) ($commissions[$id] ?? 0);
            $settledCom = (float) ($settledCommissions[$id] ?? 0);
            $paid = (float) ($paidCommissions[$id] ?? 0);

            $result[$id] = [
                'advance_pending' => round(max($advanced - $settledAdv, 0), 2),
                'commission_pending' => round(max($commissionTotal - $settledCom - $paid, 0), 2),
            ];
        }

        return $result;
    }
}

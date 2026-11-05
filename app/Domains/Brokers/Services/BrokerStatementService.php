<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BrokerStatementService
{
    /**
     * Totais do corretor. Saldos em aberto são acumulados; os do período respeitam o filtro.
     *
     * @return array<string, float>
     */
    public function summary(Broker $broker, ?string $startDate = null, ?string $endDate = null): array
    {
        $commissionIds = $broker->commissions()->pluck('id');
        $personalAdvanceIds = $broker->advances()->personal()->pluck('id');

        $advancedPeriod = (float) $this->applyPeriod(
            BrokerAdvance::where('broker_id', $broker->id)->personal(),
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

        $totalPersonalAdvanced = (float) $broker->advances()->personal()->sum('amount');
        $totalSettled = (float) BrokerCommissionSettlement::whereIn('advance_id', $personalAdvanceIds)->sum('amount_offset');
        $advancePending = max($totalPersonalAdvanced - $totalSettled, 0);

        $commissionGenerated = (float) $broker->commissions()->sum('commission_amount');
        $commissionCompensated = (float) BrokerCommissionSettlement::whereIn('commission_id', $commissionIds)->sum('amount_offset');
        $commissionRepassed = (float) $broker->commissionPayments()->sum('amount');
        $commissionPending = round(max($commissionGenerated - $commissionCompensated - $commissionRepassed, 0), 2);

        // Positivo: corretor deve ao Cassio. Negativo: Cassio deve ao corretor.
        $saldoDevedor = round($advancePending - $commissionPending, 2);

        $expensesPeriod = (float) $this->applyPeriod(
            BrokerProcessExpense::where('broker_id', $broker->id),
            'date',
            $startDate,
            $endDate,
        )->sum('amount');

        $processExpensesOpen = (float) $broker->processExpenses()
            ->with('recoveries')
            ->get()
            ->sum(fn (BrokerProcessExpense $expense) => $expense->remainingCents() / 100);

        return [
            'advanced_period' => round($advancedPeriod, 2),
            'advance_pending' => round($advancePending, 2),
            'commissions_period' => round($commissionsPeriod, 2),
            'settled_period' => round($settledPeriod, 2),
            'repassed_period' => round($repassedPeriod, 2),
            'commission_pending' => round($commissionPending, 2),
            'commission_generated' => round($commissionGenerated, 2),
            'commission_compensated' => round($commissionCompensated, 2),
            'commission_repassed' => round($commissionRepassed, 2),
            'saldo_devedor' => $saldoDevedor,
            'process_expenses_period' => round($expensesPeriod, 2),
            'process_expenses_open' => round($processExpensesOpen, 2),
            'cash_out_period' => round($advancedPeriod + $repassedPeriod + $expensesPeriod, 2),
        ];
    }

    /**
     * Extrato com saldo corrido (saldo_devedor acumulado).
     * Ordenação cronológica crescente para o cálculo; a UI pode inverter.
     */
    public function entries(Broker $broker, ?string $startDate = null, ?string $endDate = null, bool $ascending = true): Collection
    {
        $advances = $this->applyPeriod(
            $broker->advances()->personal()->with('bankAccount', 'writ', 'contact'),
            'date',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerAdvance $advance) => [
            'date' => $advance->date,
            'sort' => $advance->date->format('Y-m-d').'-A-'.$advance->id,
            'type' => 'Adiantamento pessoal',
            'description' => $this->linkDescription(
                $advance->payment_method ?: 'Adiantamento ao corretor',
                $advance->process_ref,
                $advance->writ?->process_number,
                $advance->notes,
            ),
            'amount' => (float) $advance->amount,
            'delta' => (float) $advance->amount, // aumenta o que o corretor deve
            'tone' => 'down',
            'icon' => 'account_balance_wallet',
            'affects_personal' => true,
        ]);

        $commissions = $this->applyPeriod(
            $broker->commissions()->with('caseType', 'writ'),
            'reference_date',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerCommission $commission) => [
            'date' => $commission->reference_date,
            'sort' => $commission->reference_date->format('Y-m-d').'-C-'.$commission->id,
            'type' => 'Comissão gerada',
            'description' => $this->linkDescription(
                trim(($commission->caseType?->name ?? '').($commission->name ? ' · '.$commission->name : '')
                    .' · '.$commission->calculationLabel()),
                $commission->process_ref,
                $commission->writ?->process_number,
                null,
            ),
            'amount' => (float) $commission->commission_amount,
            'delta' => -1 * (float) $commission->commission_amount, // gera valor a pagar (reduz saldo_devedor)
            'tone' => 'neutral',
            'icon' => 'percent',
            'affects_personal' => true,
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
            'sort' => Carbon::parse($settlement->settled_at)->format('Y-m-d').'-S-'.$settlement->id,
            'type' => 'Compensação',
            'description' => 'Abatido em '.($settlement->commission?->caseType?->name ?? 'comissão')
                .($settlement->commission?->name ? ' · '.$settlement->commission->name : ''),
            'amount' => (float) $settlement->amount_offset,
            // Compensação reduz adiantamento e comissão a pagar na mesma medida: saldo_devedor não muda.
            'delta' => 0.0,
            'tone' => 'up',
            'icon' => 'sync',
            'affects_personal' => true,
        ]);

        $payments = $this->applyPeriod(
            BrokerCommissionPayment::with('commission.caseType')
                ->where('broker_id', $broker->id),
            'paid_at',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerCommissionPayment $payment) => [
            'date' => $payment->paid_at,
            'sort' => $payment->paid_at->format('Y-m-d').'-P-'.$payment->id,
            'type' => 'Repasse',
            'description' => $payment->commission?->caseType?->name ?? 'Repasse de comissão',
            'amount' => (float) $payment->amount,
            'delta' => (float) $payment->amount, // ao pagar, o "Cassio deve" cai → saldo_devedor sobe
            'tone' => 'down',
            'icon' => 'payments',
            'affects_personal' => true,
        ]);

        $expenses = $this->applyPeriod(
            BrokerProcessExpense::with('writ', 'contact')->where('broker_id', $broker->id),
            'date',
            $startDate,
            $endDate,
        )->get()->map(fn (BrokerProcessExpense $expense) => [
            'date' => $expense->date,
            'sort' => $expense->date->format('Y-m-d').'-E-'.$expense->id,
            'type' => 'Despesa de processo',
            'description' => $this->linkDescription(
                $expense->description,
                $expense->process_ref,
                $expense->writ?->process_number,
                $expense->contact?->name ? 'Cliente: '.$expense->contact->name : null,
            ),
            'amount' => (float) $expense->amount,
            'delta' => 0.0, // fora do saldo pessoal
            'tone' => 'down',
            'icon' => 'receipt_long',
            'affects_personal' => false,
        ]);

        $sorted = $advances
            ->concat($expenses)
            ->concat($commissions)
            ->concat($settlements)
            ->concat($payments)
            ->sortBy('sort')
            ->values();

        $running = 0.0;
        $withBalance = $sorted->map(function (array $entry) use (&$running) {
            if ($entry['affects_personal']) {
                $running = round($running + $entry['delta'], 2);
            }
            $entry['running_balance'] = $running;

            return $entry;
        });

        return $ascending ? $withBalance : $withBalance->reverse()->values();
    }

    private function linkDescription(string $base, ?string $processRef, ?string $writProcess, ?string $extra): string
    {
        $parts = array_filter([
            trim($base) !== '' ? trim($base) : null,
            $processRef ? 'Processo '.$processRef : null,
            $writProcess ? 'Req. '.$writProcess : null,
            $extra,
        ]);

        return implode(' · ', $parts);
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

<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Carbon;

class DashboardSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $previousStart = $monthStart->copy()->subMonthNoOverflow();
        $previousEnd = $previousStart->copy()->endOfMonth();
        $upcoming30 = $now->copy()->addDays(30);

        // ---- Banking ----
        $accounts = BankAccount::active()->get();
        $totalBalance = $accounts->sum(fn ($a) => $a->balance());

        $openInvoices = CreditCardInvoice::with('creditCard')
            ->whereIn('status', ['open', 'closed', 'partially_paid'])
            ->get();
        $openInvoicesTotal = $openInvoices->sum(fn ($i) => $i->remainingAmount());

        $upcomingInvoices = $openInvoices
            ->where('due_date', '>=', $now)
            ->where('due_date', '<=', $upcoming30)
            ->sortBy('due_date')
            ->take(10)
            ->map(fn ($i) => [
                'id' => $i->id,
                'card' => $i->creditCard?->name,
                'reference_month' => $i->reference_month,
                'due_date' => $i->due_date->format('Y-m-d'),
                'remaining' => round($i->remainingAmount(), 2),
            ])->values();

        [$incomeMonth, $expenseMonth] = $this->incomeExpenseFor($monthStart, $monthEnd);
        [$incomePrev, $expensePrev] = $this->incomeExpenseFor($previousStart, $previousEnd);

        $resultByMonth = $this->resultsForLastMonths(12, $now);
        $avg3 = $this->avgOfLastN($resultByMonth, 3);
        $avg6 = $this->avgOfLastN($resultByMonth, 6);
        $avg12 = $this->avgOfLastN($resultByMonth, 12);

        $pendingReceivable = (float) Transaction::where('status', 'pending')->where('type', 'income')->sum('amount');
        $pendingPayable = (float) Transaction::where('status', 'pending')
            ->whereIn('type', ['expense', 'invoice_payment'])
            ->sum('amount');

        $balancesByAccount = $accounts->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'balance' => round($a->balance(), 2),
        ])->values();

        // ---- Investments ----
        $positions = AssetPosition::with('asset.assetClass')->where('quantity', '>', 0)->get();
        $portfolioMarketValue = (float) $positions->sum(fn ($p) => $p->marketValue());
        $portfolioInvested = (float) $positions->sum('total_invested');
        $portfolioByClass = $positions->groupBy(fn ($p) => $p->asset?->assetClass?->name ?? '—')
            ->map(fn ($g) => [
                'invested' => round($g->sum('total_invested'), 2),
                'market_value' => round($g->sum(fn ($p) => $p->marketValue()), 2),
            ]);
        $dividends12m = (float) AssetDividend::where('payment_date', '>=', $now->copy()->subYear())->sum('total');

        // ---- Partnership ----
        $partnerships = Partnership::active()->get();
        $partnershipNetExposed = 0.0;
        $partnershipsList = [];
        foreach ($partnerships as $p) {
            $exposed = $p->totalContributed() + $p->totalExpenses() - $p->totalDistributions();
            $partnershipNetExposed += max(0.0, $exposed);
            $partnershipsList[] = [
                'id' => $p->id,
                'name' => $p->name,
                'exposed' => round(max(0.0, $exposed), 2),
                'net_result' => round($p->netResult(), 2),
            ];
        }

        // ---- Writs ----
        $writsActive = Writ::whereIn('stage', ['paid', 'petitioning'])->get();
        $writsCapitalAtRisk = (float) $writsActive->sum('paid_amount');
        $writsExpectedNet = $writsActive->sum(fn ($w) => max(0.0, (float) $w->estimated_receipt_amount - (float) $w->paid_amount));
        $writsByStage = collect(Writ::STAGES)->map(function (string $stage) {
            $rows = Writ::where('stage', $stage)->get();
            return [
                'stage' => $stage,
                'label' => Writ::STAGE_LABELS[$stage],
                'count' => $rows->count(),
                'face_total' => round((float) $rows->sum('face_value'), 2),
                'paid_total' => round((float) $rows->sum('paid_amount'), 2),
            ];
        })->values();

        // ---- Brokers ----
        $totalAdvances = (float) BrokerAdvance::sum('amount');
        $totalSettled = (float) BrokerCommissionSettlement::sum('amount_offset');
        $advancesOutstanding = round(max(0.0, $totalAdvances - $totalSettled), 2);

        // ---- Future contributions (Sociedade pendente) ----
        $futureContributions = \App\Domains\Partnership\Models\PartnershipContribution::where('status', 'pending')
            ->orderBy('date')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'partnership' => $c->partnership?->name,
                'date' => $c->date->format('Y-m-d'),
                'amount' => round((float) $c->amount, 2),
            ])->values();

        // ---- Patrimônio total ----
        $patrimony = round(
            $totalBalance
            + $portfolioMarketValue
            + $partnershipNetExposed
            + $writsCapitalAtRisk
            - $openInvoicesTotal,
            2
        );

        $distribution = collect([
            ['label' => 'Caixa em contas', 'value' => round($totalBalance, 2)],
        ])
            ->concat($portfolioByClass->map(fn ($v, $k) => [
                'label' => "Carteira · {$k}",
                'value' => $v['market_value'],
            ])->values())
            ->concat([
                ['label' => 'Sociedade (capital exposto)', 'value' => round($partnershipNetExposed, 2)],
                ['label' => 'Requisitórios em aberto', 'value' => round($writsCapitalAtRisk, 2)],
            ])
            ->filter(fn ($r) => $r['value'] > 0)
            ->values();

        return [
            'generated_at' => $now->toIso8601String(),
            'reference_month' => $monthStart->format('Y-m'),
            'patrimony' => [
                'total' => $patrimony,
                'cash_balance' => round($totalBalance, 2),
                'portfolio_market_value' => round($portfolioMarketValue, 2),
                'partnership_exposed' => round($partnershipNetExposed, 2),
                'writs_capital_at_risk' => round($writsCapitalAtRisk, 2),
                'open_invoices_total' => round($openInvoicesTotal, 2),
            ],
            'distribution' => $distribution,
            'month' => [
                'income' => round($incomeMonth, 2),
                'expense' => round($expenseMonth, 2),
                'result' => round($incomeMonth - $expenseMonth, 2),
            ],
            'previous_month' => [
                'income' => round($incomePrev, 2),
                'expense' => round($expensePrev, 2),
                'result' => round($incomePrev - $expensePrev, 2),
            ],
            'averages' => [
                'last_3_months' => round($avg3, 2),
                'last_6_months' => round($avg6, 2),
                'last_12_months' => round($avg12, 2),
            ],
            'pending' => [
                'receivable' => round($pendingReceivable, 2),
                'payable' => round($pendingPayable, 2),
            ],
            'upcoming_invoices' => $upcomingInvoices,
            'balances_by_account' => $balancesByAccount,
            'investments' => [
                'invested' => round($portfolioInvested, 2),
                'market_value' => round($portfolioMarketValue, 2),
                'dividends_12m' => round($dividends12m, 2),
                'by_class' => $portfolioByClass,
            ],
            'partnerships' => [
                'count' => $partnerships->count(),
                'list' => $partnershipsList,
            ],
            'writs' => [
                'capital_at_risk' => round($writsCapitalAtRisk, 2),
                'expected_net_profit' => round((float) $writsExpectedNet, 2),
                'by_stage' => $writsByStage,
            ],
            'brokers' => [
                'advances_outstanding' => $advancesOutstanding,
            ],
            'future_contributions' => $futureContributions,
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function incomeExpenseFor(Carbon $start, Carbon $end): array
    {
        $income = (float) Transaction::where('type', 'income')
            ->where('status', 'settled')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        $expense = (float) Transaction::whereIn('type', ['expense', 'invoice_payment'])
            ->where('status', 'settled')
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        return [$income, $expense];
    }

    /**
     * @return array<int, array{month: string, result: float}>
     */
    private function resultsForLastMonths(int $n, Carbon $reference): array
    {
        $rows = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $start = $reference->copy()->subMonthsNoOverflow($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            [$inc, $exp] = $this->incomeExpenseFor($start, $end);
            $rows[] = ['month' => $start->format('Y-m'), 'result' => $inc - $exp];
        }
        return $rows;
    }

    /**
     * @param  array<int, array{month: string, result: float}>  $rows
     */
    private function avgOfLastN(array $rows, int $n): float
    {
        $slice = array_slice($rows, -$n);
        if (count($slice) === 0) return 0.0;
        return array_sum(array_column($slice, 'result')) / count($slice);
    }
}

<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Banking\Services\CashPositionService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use App\Domains\Dashboard\Support\ReportPeriod;
use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Carbon;

/**
 * Dados do Dashboard que dependem do período escolhido.
 * Fluxos respeitam [início, fim]; posições usam a data de posição do período (último dia, no máximo hoje).
 */
class DashboardWidgetService
{
    public function __construct(private OwnFinancialService $own, private CashflowReportService $cashflow) {}

    public function build(ReportPeriod $period, ?string $regime = null): array
    {
        $regime ??= OwnFinancialService::currentRegime();
        $asOf = $period->positionDate();
        $asOfStr = $asOf->toDateString();

        $totals = $this->own->totals($period->start, $period->end, $regime);
        $prev = $period->previous();
        $prevTotals = $this->own->totals($prev->start, $prev->end, $regime);

        $months = [];
        foreach ($period->months() as $m) {
            $t = $this->own->totals($m['start'], $m['end'], $regime);
            $months[] = ['label' => $m['label'], 'income' => round($t['income'], 2), 'expense' => round($t['expense'], 2), 'result' => round($t['result'], 2)];
        }

        $accounts = BankAccount::active()->orderBy('name')->get();
        // Posição de hoje = mesmo cálculo de /banking (todos os liquidados); datas passadas cortam no dia.
        $until = $period->isPositionToday() ? null : $asOfStr;
        $cash = app(CashPositionService::class)->summarize($accounts, $until);

        return [
            'period' => ['label' => $period->label(), 'start' => $period->start->toDateString(), 'end' => $period->end->toDateString(), 'position_date' => $asOfStr, 'position_is_today' => $period->isPositionToday()],
            'regime' => $regime,
            'flows' => [
                'income' => round($totals['income'], 2),
                'expense' => round($totals['expense'], 2),
                'result' => round($totals['result'], 2),
                'months' => $months,
                'comparison' => ReportPeriod::change($totals['result'], $prevTotals['result']),
                'previous_label' => $prev->label(),
            ],
            'averages' => $this->movingAverages($asOf, $regime),
            'cash' => $cash,
            'accounts' => [
                'own' => $accounts->where('type', '!=', 'repasse')->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'balance' => round($a->balance($until), 2)])->values()->all(),
                'repasse' => $accounts->where('type', 'repasse')->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'balance' => round($a->balance($until), 2)])->values()->all(),
            ],
            'invoices' => $this->invoicesAt($asOf, $period->isPositionToday()),
            'receivables' => $this->receivablesAt($asOfStr),
            'loans' => $this->loansAt($asOfStr),
            'brokers' => $this->brokers(),
            'writs_upcoming' => $this->writsUpcoming($asOf),
            'expenses_by_category' => $this->expensesByCategory($period, $regime),
        ];
    }

    /** Médias de 3/6/12 meses fechando no mês da data de posição + série de 12 meses com média móvel de 3. */
    public function movingAverages(Carbon $asOf, string $regime): array
    {
        $rows = [];
        for ($i = 11; $i >= 0; $i--) {
            $s = $asOf->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $t = $this->own->totals($s, $s->copy()->endOfMonth(), $regime);
            $rows[] = ['label' => ReportPeriod::monthLabel($s), 'result' => round($t['result'], 2)];
        }
        $avg = fn (int $n) => round(array_sum(array_column(array_slice($rows, -$n), 'result')) / $n, 2);
        foreach ($rows as $i => &$row) {
            $slice = array_slice($rows, max(0, $i - 2), min(3, $i + 1));
            $row['avg3'] = round(array_sum(array_column($slice, 'result')) / count($slice), 2);
        }

        return ['last_3' => $avg(3), 'last_6' => $avg(6), 'last_12' => $avg(12), 'series' => $rows];
    }

    /** Faturas com saldo na data: a vencer (vencimento >= data) e vencidas. Hoje usa o saldo atual da fatura. */
    public function invoicesAt(Carbon $asOf, bool $isToday): array
    {
        $invoices = CreditCardInvoice::with('creditCard')->get()->map(function ($i) use ($asOf, $isToday) {
            if ($isToday) {
                $remaining = in_array($i->status, ['open', 'closed', 'partially_paid'], true) ? $i->remainingAmount() : 0;
            } else {
                $opened = ($i->closing_date ?? $i->due_date)?->copy()->subMonthNoOverflow();
                if (! $opened || $opened->gt($asOf)) {
                    return null;
                }
                $paid = (float) $i->transactions()->where('type', 'invoice_payment')->where('status', 'settled')->whereDate('date', '<=', $asOf->toDateString())->sum('amount');
                $remaining = max(0, (float) $i->total - $paid);
            }
            if ($remaining <= 0.004) {
                return null;
            }

            return ['id' => $i->id, 'card' => $i->creditCard?->name, 'reference_month' => $i->reference_month, 'due_date' => $i->due_date?->toDateString(), 'remaining' => round($remaining, 2), 'overdue' => $i->due_date && $i->due_date->lt($asOf)];
        })->filter()->sortBy('due_date')->values();

        return [
            'upcoming_total' => round($invoices->where('overdue', false)->sum('remaining'), 2),
            'overdue_total' => round($invoices->where('overdue', true)->sum('remaining'), 2),
            'upcoming_count' => $invoices->where('overdue', false)->count(),
            'overdue_count' => $invoices->where('overdue', true)->count(),
            'list' => $invoices->take(8)->all(),
        ];
    }

    public function receivablesAt(string $asOf): array
    {
        $rows = Receivable::with('contact', 'installments.settlements', 'settlements')->where('is_test', false)->whereDate('date', '<=', $asOf)->get()
            ->map(fn ($r) => ['name' => $r->contact?->name ?? $r->description, 'balance' => $r->balanceAsOf($asOf)])
            ->filter(fn ($r) => $r['balance']['balance'] > 0);

        return [
            'count' => $rows->count(),
            'principal' => round($rows->sum(fn ($r) => $r['balance']['principal_open']) / 100, 2),
            'total' => round($rows->sum(fn ($r) => $r['balance']['balance']) / 100, 2),
        ];
    }

    public function loansAt(string $asOf): array
    {
        $rows = Loan::with('contact', 'installments', 'settlements')->where('is_test', false)->whereDate('date', '<=', $asOf)->get()
            ->map(fn ($l) => ['name' => $l->contact?->name ?? $l->description, 'balance' => $l->balanceAsOf($asOf), 'status' => $l->statusLabel($asOf)])
            ->filter(fn ($r) => $r['balance']['balance'] > 0);

        return [
            'count' => $rows->count(),
            'overdue' => $rows->where('status', 'Vencido')->count(),
            'principal' => round($rows->sum(fn ($r) => $r['balance']['principal_open']) / 100, 2),
            'total' => round($rows->sum(fn ($r) => $r['balance']['balance']) / 100, 2),
        ];
    }

    /** Saldo com corretores (posição atual): adiantamentos a compensar e comissões a pagar. */
    public function brokers(): array
    {
        $balances = app(BrokerBalanceCalculator::class)->pendingBalancesFor(Broker::pluck('id')->all());

        return [
            'advances' => round(array_sum(array_column($balances, 'advance_pending')), 2),
            'commissions' => round(array_sum(array_column($balances, 'commission_pending')), 2),
            'count' => count(array_filter($balances, fn ($b) => $b['advance_pending'] > 0.004 || $b['commission_pending'] > 0.004)),
        ];
    }

    /** Requisitórios adquiridos (Pago, Peticionamento, Aguardando) por mês previsto; atrasados ficam à parte. Nunca Monitorar. */
    public function writsUpcoming(Carbon $asOf, int $months = 6): array
    {
        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $asOf->copy()->startOfMonth()->addMonthsNoOverflow($i);
            $buckets[$m->format('Y-m')] = ['label' => ReportPeriod::monthLabel($m), 'count' => 0, 'amount' => 0.0, 'profit' => 0.0];
        }
        $overdue = ['count' => 0, 'amount' => 0.0];
        $noDate = 0;
        foreach (Writ::whereIn('stage', Writ::ACQUIRED_OPEN_STAGES)->get() as $w) {
            $deadline = $w->estimatedReceiptDeadline();
            $amount = (float) $w->estimated_receipt_amount;
            if (! $deadline) {
                $noDate++;
                continue;
            }
            if ($deadline->lt($asOf->copy()->startOfMonth())) {
                $overdue['count']++;
                $overdue['amount'] += $amount;
                continue;
            }
            $key = $deadline->format('Y-m');
            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
                $buckets[$key]['amount'] = round($buckets[$key]['amount'] + $amount, 2);
                $buckets[$key]['profit'] = round($buckets[$key]['profit'] + $w->receiptSplit($amount)['profit'], 2);
            }
        }

        return ['months' => array_values($buckets), 'overdue' => ['count' => $overdue['count'], 'amount' => round($overdue['amount'], 2)], 'without_date' => $noDate, 'total' => round(array_sum(array_column($buckets, 'amount')), 2)];
    }

    public function expensesByCategory(ReportPeriod $period, string $regime): array
    {
        $realized = $this->cashflow->realized($period->start, $period->end, ['account_id' => null, 'category_id' => null, 'person' => null], $regime);

        return collect($realized['by_category'])
            ->map(fn ($r, $name) => ['label' => $name, 'value' => round($r['own_expense'], 2)])
            ->filter(fn ($r) => $r['value'] > 0.004)
            ->sortByDesc('value')->values()->all();
    }
}

<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\RecurringTransaction;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Dashboard\Support\ReportPeriod;
use App\Domains\Writs\Models\Writ;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fluxo de caixa (Etapa 7): realizado x previsto, por categoria/conta, comparação e projeção.
 * Regras do resultado próprio vêm de OwnFinancialService (cartão por regime, principal fora do resultado,
 * juros recebidos como receita, repasses/transferências fora). Previsto nunca usa requisitórios em Monitorar.
 */
class CashflowReportService
{
    public const CAPITAL_NAME = 'Retorno de capital (RPV/Precatórios)';

    public function __construct(private OwnFinancialService $own) {}

    /** @param array{account_id?:?int,category_id?:?int,person?:?string,regime?:?string} $filters */
    public function build(ReportPeriod $period, array $filters = []): array
    {
        $regime = $filters['regime'] ?? OwnFinancialService::currentRegime();
        $f = $this->cleanFilters($filters);

        $realized = $this->realized($period->start, $period->end, $f, $regime);
        $previous = $this->realized($period->previous()->start, $period->previous()->end, $f, $regime);
        $forecast = $this->forecast($period->start, $period->end, $f);

        $months = [];
        foreach ($period->months() as $m) {
            $r = $this->ownTotals($m['start'], $m['end'], $f, $regime);
            $months[] = ['key' => $m['key'], 'label' => $m['label'], 'income' => $r['income'], 'expense' => $r['expense'], 'result' => $r['result']];
        }

        $position = $period->positionDate();
        $cash = app(CashPositionService::class)->summarize(BankAccount::active()->get(), $period->isPositionToday() ? null : $position->toDateString());

        return [
            'regime' => $regime,
            'filters' => $f,
            'realized' => $realized,
            'previous' => $previous,
            'comparison' => [
                'income' => ReportPeriod::change($realized['own_income'], $previous['own_income']),
                'expense' => ReportPeriod::change($realized['own_expense'], $previous['own_expense']),
                'result' => ReportPeriod::change($realized['result'], $previous['result']),
            ],
            'forecast' => $forecast,
            'months' => $months,
            'position_date' => $position->toDateString(),
            'cash' => $cash,
        ];
    }

    public function cleanFilters(array $filters): array
    {
        $person = isset($filters['person']) && is_string($filters['person']) && preg_match('/^(cassio|luciane|filho:.{1,80}|contato:\d+)$/u', $filters['person']) ? $filters['person'] : null;

        return [
            'account_id' => ! empty($filters['account_id']) ? (int) $filters['account_id'] : null,
            'category_id' => ! empty($filters['category_id']) ? (int) $filters['category_id'] : null,
            'person' => $person,
        ];
    }

    /** Lançamentos liquidados do período com os filtros aplicados. */
    public function settledQuery(Carbon $start, Carbon $end, array $f): Builder
    {
        $query = Transaction::with(['category', 'allocations.category', 'processReceipt.category', 'destinations.recoveries', 'destinations.payments.writIncome.sourceWrit', 'destinations.payments', 'sourceWrit', 'bankAccount', 'creditCard', 'receivableInstallments', 'receivableSettlements', 'loanSettlements', 'destinationPayment', 'invoice'])
            ->whereBetween('date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('status', 'settled')
            ->whereIn('type', ['income', 'expense', 'invoice_payment'])
            ->notHistorical();

        return $this->applyFilters($query, $f);
    }

    public function applyFilters(Builder $query, array $f): Builder
    {
        if ($f['account_id'] ?? null) {
            $query->where('bank_account_id', $f['account_id']);
        }
        if ($f['category_id'] ?? null) {
            $id = $f['category_id'];
            $query->where(fn ($q) => $q->where('category_id', $id)->orWhereHas('allocations', fn ($a) => $a->where('category_id', $id))
                ->orWhereHas('processReceipt', fn ($p) => $p->where('category_id', $id)));
        }
        if ($person = $f['person'] ?? null) {
            $query->where(function ($q) use ($person) {
                if (str_starts_with($person, 'contato:')) {
                    $id = (int) substr($person, 8);
                    $q->where('link_contact_id', $id)
                        ->orWhereHas('creditCard', fn ($c) => $c->where('holder_type', 'contato')->where('holder_contact_id', $id))
                        ->orWhereHas('receivableInstallments.receivable', fn ($r) => $r->where('contact_id', $id))
                        ->orWhereHas('receivableSettlements.receivableInstallment.receivable', fn ($r) => $r->where('contact_id', $id))
                        ->orWhereHas('loanSettlements.loan', fn ($l) => $l->where('contact_id', $id));
                } elseif (str_starts_with($person, 'filho:')) {
                    $name = substr($person, 6);
                    $q->whereHas('creditCard', fn ($c) => $c->where('holder_type', 'filho')->whereRaw('LOWER(TRIM(holder_name)) = ?', [$name]));
                } else {
                    $q->whereHas('creditCard', fn ($c) => $c->where('holder_type', $person));
                }
            });
        }

        return $query;
    }

    public function ownTotals(Carbon $start, Carbon $end, array $f, string $regime): array
    {
        if (! array_filter($f)) {
            return $this->own->totals($start->copy()->startOfDay(), $end->copy()->endOfDay(), $regime);
        }
        $rows = $this->settledQuery($start, $end, $f)->get();
        if ($f['category_id'] ?? null) {
            $name = Category::find($f['category_id'])?->name;
            $cat = $rows->flatMap(fn ($t) => $this->categoryRows($t, $regime))->where('category', $name);
            $income = (float) $cat->where('type', 'income')->sum('own_amount');
            $expense = (float) $cat->whereIn('type', ['expense', 'invoice_payment'])->sum('own_amount');

            return ['income' => round($income, 2), 'expense' => round($expense, 2), 'result' => round($income - $expense, 2)];
        }
        $income = $expense = 0;
        foreach ($rows as $row) {
            $v = $this->own->ownCents($row, $regime);
            $row->type === 'income' ? $income += $v : $expense += $v;
        }

        return ['income' => $income / 100, 'expense' => $expense / 100, 'result' => ($income - $expense) / 100];
    }

    public function realized(Carbon $start, Carbon $end, array $f, string $regime): array
    {
        $rows = $this->settledQuery($start, $end, $f)->get();
        $categoryName = ($f['category_id'] ?? null) ? Category::find($f['category_id'])?->name : null;

        $byCategory = $rows->flatMap(fn ($t) => $this->categoryRows($t, $regime))
            ->when($categoryName !== null, fn ($c) => $c->where('category', $categoryName))
            ->groupBy('category')
            ->map(fn ($group) => [
                'income' => round($group->where('type', 'income')->sum('amount'), 2),
                'expense' => round($group->whereIn('type', ['expense', 'invoice_payment'])->sum('amount'), 2),
                'own_income' => round($group->where('type', 'income')->sum('own_amount'), 2),
                'own_expense' => round($group->whereIn('type', ['expense', 'invoice_payment'])->sum('own_amount'), 2),
                'third_party_in' => round($group->where('type', 'income')->sum(fn ($r) => $r['amount'] - $r['own_amount']), 2),
                'third_party_out' => round($group->whereIn('type', ['expense', 'invoice_payment'])->sum(fn ($r) => $r['amount'] - $r['own_amount']), 2),
            ])->sortKeys();

        $accountQuery = Transaction::with('bankAccount')
            ->whereBetween('date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where('status', 'settled')->whereNotNull('bank_account_id')
            ->whereHas('bankAccount', fn ($q) => $q->where('type', '!=', 'repasse'))
            ->notHistorical();
        $byAccount = $this->applyFilters($accountQuery, $f)->get()
            ->groupBy(fn ($t) => $t->bankAccount?->name ?? '—')
            ->map(fn ($g) => [
                'in' => round($g->where('type', 'income')->sum('amount') + $g->where('type', 'transfer')->where('amount', '>', 0)->sum('amount'), 2),
                'out' => round($g->whereIn('type', ['expense', 'invoice_payment'])->sum('amount') + abs((float) $g->where('type', 'transfer')->where('amount', '<', 0)->sum('amount')), 2),
            ])->sortKeys();

        $totals = $this->ownTotals($start, $end, $f, $regime);

        $destinations = ReceiptDestination::with('commission', 'payments.transaction', 'recoveries')
            ->whereHas('receipt', function ($q) use ($start, $end, $f) {
                $q->where('type', 'income')->where('status', 'settled')->whereBetween('date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
                $this->applyFilters($q, $f);
            })->get();
        $recovered = $destinations->sum(fn ($p) => $p->recoveredCents()) / 100;
        $acquired = $destinations->sum(fn ($p) => (float) $p->payments->sum('writ_amount'));
        $thirdPartyIncome = $destinations->where('kind', '!=', 'own')->sum('amount') - $recovered - $acquired - $destinations->sum(fn ($p) => $p->offsetCents()) / 100;

        $grossIn = (float) $byAccount->sum('in');
        $grossOut = (float) $byAccount->sum('out');

        return [
            'by_category' => $byCategory,
            'by_account' => $byAccount,
            'own_income' => round($totals['income'], 2),
            'own_expense' => round($totals['expense'], 2),
            'result' => round($totals['result'], 2),
            'gross_in' => round($grossIn, 2),
            'gross_out' => round($grossOut, 2),
            'cash_variation' => round($grossIn - $grossOut, 2),
            'third_party_income' => round($thirdPartyIncome, 2),
            'recovered_expenses' => round($recovered, 2),
            'pending_repasses' => round($destinations->sum(fn ($p) => $p->remainingCents()) / 100, 2),
            'writ_profit' => round((float) ($byCategory[Category::WRIT_PROFIT_NAME]['own_income'] ?? 0), 2),
            'writ_capital' => round((float) ($byCategory[self::CAPITAL_NAME]['own_income'] ?? 0), 2),
        ];
    }

    /**
     * Previsto no intervalo: lançamentos pendentes, faturas com vencimento no intervalo (saldo restante),
     * recorrências ativas ainda não geradas e requisitórios adquiridos com previsão (Pago, Peticionamento, Aguardando).
     *
     * @return array{items: list<array>, income: float, expense: float, net: float}
     */
    public function forecast(Carbon $start, Carbon $end, array $f, ?Carbon $from = null): array
    {
        $from ??= Carbon::parse(\App\Support\BusinessDate::today())->startOfDay();
        $items = [];
        $overdueWrits = ['count' => 0, 'amount' => 0.0];
        $s = $start->copy()->startOfDay();
        $e = $end->copy()->endOfDay();

        $pending = $this->applyFilters(Transaction::with('category', 'bankAccount')
            ->where('status', 'pending')->whereIn('type', ['income', 'expense', 'invoice_payment'])
            ->whereBetween('date', [$s, $e])
            ->where(fn ($q) => $q->whereNull('credit_card_invoice_id')->orWhere('type', 'income'))
            ->where(fn ($q) => $q->whereNull('bank_account_id')->orWhereHas('bankAccount', fn ($a) => $a->where('type', '!=', 'repasse'))), $f)->get();
        foreach ($pending as $t) {
            $items[] = ['date' => $t->date->toDateString(), 'source' => 'Pendente', 'description' => $t->description, 'category' => $t->category?->name, 'direction' => $t->type === 'income' ? 'in' : 'out', 'amount' => round((float) $t->amount, 2)];
        }

        if (! ($f['category_id'] ?? null)) {
            $invoices = CreditCardInvoice::with('creditCard')->whereIn('status', ['open', 'closed', 'partially_paid'])
                ->whereBetween('due_date', [$s, $e])->get()
                ->filter(fn ($i) => $i->remainingAmount() > 0.004)
                ->filter(fn ($i) => ! ($f['account_id'] ?? null) || (int) $i->creditCard?->default_payment_account_id === (int) $f['account_id'])
                ->filter(fn ($i) => ! ($f['person'] ?? null) || ($i->creditCard && $i->creditCard->holderKey() === $f['person']));
            foreach ($invoices as $i) {
                $items[] = ['date' => $i->due_date->toDateString(), 'source' => 'Fatura', 'description' => 'Fatura '.($i->creditCard?->name ?? '').' '.$i->reference_month, 'category' => 'Pagamento de fatura', 'direction' => 'out', 'amount' => round($i->remainingAmount(), 2)];
            }
        }

        if (! ($f['person'] ?? null)) {
            $recurring = RecurringTransaction::with('category')->active()->whereIn('type', ['income', 'expense'])->whereNull('credit_card_id')
                ->when($f['account_id'] ?? null, fn ($q, $id) => $q->where('bank_account_id', $id))
                ->when($f['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))->get();
            foreach ($recurring as $rec) {
                foreach ($this->recurringDates($rec, $s->copy()->max($from->copy()->addDay()), $e) as $date) {
                    $items[] = ['date' => $date, 'source' => 'Recorrência', 'description' => $rec->description, 'category' => $rec->category?->name, 'direction' => $rec->type === 'income' ? 'in' : 'out', 'amount' => round((float) $rec->amount, 2)];
                }
            }
        }

        if (! ($f['person'] ?? null) && ! ($f['category_id'] ?? null)) {
            $writs = Writ::whereIn('stage', Writ::ACQUIRED_OPEN_STAGES)
                ->when($f['account_id'] ?? null, fn ($q, $id) => $q->where('destination_bank_account_id', $id))->get();
            foreach ($writs as $w) {
                $deadline = $w->estimatedReceiptDeadline();
                $amount = (float) $w->estimated_receipt_amount;
                if (! $deadline || $amount <= 0) {
                    continue;
                }
                // Previsão já vencida não vira entrada automática (não inflar a projeção): fica listada à parte.
                if ($deadline->lt($from)) {
                    $overdueWrits['count']++;
                    $overdueWrits['amount'] = round($overdueWrits['amount'] + $amount, 2);
                    continue;
                }
                $date = $deadline->copy();
                if ($date->lt($s) || $date->gt($e)) {
                    continue;
                }
                $split = $w->receiptSplit($amount);
                $items[] = ['date' => $date->toDateString(), 'source' => 'Requisitório', 'description' => trim(($w->process_number ?: 'Requisitório #'.$w->id).' · '.$w->stageLabel()), 'category' => Category::WRIT_PROFIT_NAME, 'direction' => 'in', 'amount' => round($amount, 2), 'profit' => $split['profit'], 'capital' => $split['capital']];
            }
        }

        usort($items, fn ($a, $b) => [$a['date'], $a['source']] <=> [$b['date'], $b['source']]);
        $in = round(array_sum(array_map(fn ($i) => $i['direction'] === 'in' ? $i['amount'] : 0, $items)), 2);
        $out = round(array_sum(array_map(fn ($i) => $i['direction'] === 'out' ? $i['amount'] : 0, $items)), 2);

        return ['items' => $items, 'income' => $in, 'expense' => $out, 'net' => round($in - $out, 2), 'overdue_writs' => $overdueWrits];
    }

    /** Projeção mensal a partir de amanhã sobre o saldo próprio de hoje (consistente com /banking). */
    public function projection(int $months, array $filters = []): array
    {
        $months = in_array($months, [3, 6, 12], true) ? $months : 3;
        $f = $this->cleanFilters($filters);
        $today = Carbon::parse(\App\Support\BusinessDate::today())->startOfDay();
        $cash = app(CashPositionService::class)->summarize(BankAccount::active()->get());
        $balance = ($f['account_id'] ?? null)
            ? round((float) (BankAccount::find($f['account_id'])?->balance() ?? 0), 2)
            : $cash['own_balance'];
        $start = $balance;
        $rows = [];
        $overdue = $this->forecast($today, $today, $f, $today)['overdue_writs'];
        for ($i = 0; $i < $months; $i++) {
            $mStart = $i === 0 ? $today->copy()->addDay() : $today->copy()->startOfMonth()->addMonthsNoOverflow($i);
            $mEnd = $today->copy()->startOfMonth()->addMonthsNoOverflow($i)->endOfMonth();
            if ($mStart->gt($mEnd)) {
                $rows[] = ['key' => $mEnd->format('Y-m'), 'label' => ReportPeriod::monthLabel($mEnd), 'income' => 0.0, 'expense' => 0.0, 'net' => 0.0, 'balance' => $balance];
                continue;
            }
            $fc = $this->forecast($mStart, $mEnd, $f, $today);
            $balance = round($balance + $fc['net'], 2);
            $rows[] = ['key' => $mEnd->format('Y-m'), 'label' => ReportPeriod::monthLabel($mEnd), 'income' => $fc['income'], 'expense' => $fc['expense'], 'net' => $fc['net'], 'balance' => $balance];
        }

        return ['months' => $months, 'start_balance' => $start, 'rows' => $rows, 'end_balance' => $balance, 'overdue_writs' => $overdue];
    }

    /** @return list<string> */
    public function recurringDates(RecurringTransaction $rec, Carbon $from, Carbon $to): array
    {
        $dates = [];
        $from = $from->copy()->startOfDay()->max($rec->start_date->copy()->startOfDay());
        if ($rec->last_run_date) {
            $from = $from->max($rec->last_run_date->copy()->addDay()->startOfDay());
        }
        $to = $to->copy()->startOfDay();
        if ($rec->end_date) {
            $to = $to->min($rec->end_date->copy()->startOfDay());
        }
        $guard = 0;
        for ($d = $from->copy(); $d->lte($to) && $guard < 400; $d->addDay(), $guard++) {
            $day = $rec->day_of_month ?? $rec->start_date->day;
            $hit = match ($rec->frequency) {
                'daily' => true,
                'weekly' => $rec->start_date->dayOfWeek === $d->dayOfWeek,
                'monthly' => $day === $d->day || ($d->isLastOfMonth() && $day > $d->day),
                'yearly' => $rec->start_date->month === $d->month && $rec->start_date->day === $d->day,
                default => false,
            };
            if ($hit) {
                $dates[] = $d->toDateString();
            }
        }

        return $dates;
    }

    /** Linhas por categoria de um lançamento (mesma regra do relatório anterior, sem duplicar Juros Ganho e Retorno de capital). */
    public function categoryRows(Transaction $transaction, ?string $regime = null): array
    {
        $regime ??= OwnFinancialService::currentRegime();
        $accrual = $regime === OwnFinancialService::ACCRUAL;
        if (($transaction->credit_card_invoice_id && $transaction->type === 'expense' && ! $accrual) || ($accrual && $transaction->type === 'invoice_payment' && $transaction->credit_card_invoice_id) || $transaction->bankAccount?->type === 'repasse') {
            return [];
        }
        $ownCents = $this->own->ownCents($transaction, $regime);
        $profitName = Category::WRIT_PROFIT_NAME;

        if ($transaction->type === 'income' && $transaction->source_type === Writ::class && (float) $transaction->amount > 0) {
            $writ = $transaction->relationLoaded('sourceWrit') ? $transaction->sourceWrit : Writ::find($transaction->source_id);
            if ($writ) {
                $split = $writ->receiptSplit((float) $transaction->amount);
                $scale = (float) $transaction->amount > 0 ? ($ownCents / 100) / (float) $transaction->amount : 0.0;

                return [
                    ['category' => $profitName, 'type' => 'income', 'amount' => $split['profit'], 'own_amount' => round($split['profit'] * $scale, 2)],
                    ['category' => self::CAPITAL_NAME, 'type' => 'income', 'amount' => $split['capital'], 'own_amount' => round($split['capital'] * $scale, 2)],
                ];
            }
        }

        if ($transaction->processReceipt) {
            $own = $ownCents / 100;
            $recovered = $transaction->destinations->sum(fn ($part) => $part->recoveredCents()) / 100;
            $acquired = $transaction->destinations->sum(fn ($part) => (float) $part->payments->sum('writ_amount'));
            $profit = 0.0;
            foreach ($transaction->destinations as $part) {
                foreach ($part->payments as $payment) {
                    $linked = (float) $payment->writ_amount;
                    if ($linked <= 0 || ! $payment->writ_income_id) {
                        continue;
                    }
                    $income = $payment->relationLoaded('writIncome') ? $payment->writIncome : $payment->writIncome()->first();
                    $writ = $income?->sourceWrit ?? ($income ? Writ::find($income->source_id) : null);
                    if (! $writ) {
                        continue;
                    }
                    $baseReceipt = (float) ($writ->actual_receipt_amount ?? 0);
                    $profit += $baseReceipt > 0
                        ? round(((float) ($writ->actualProfit() ?? 0)) * min(1, $linked / $baseReceipt), 2)
                        : $writ->receiptSplit($linked)['profit'];
                }
            }
            $profit = round($profit, 2);
            $capital = round($acquired - $profit, 2);

            return [
                ['category' => $transaction->processReceipt->category?->name ?? '— sem categoria —', 'type' => 'income', 'amount' => $own - $acquired, 'own_amount' => $own - $acquired],
                ['category' => 'Valores para repasse', 'type' => 'income', 'amount' => (float) $transaction->amount - $own - $recovered, 'own_amount' => 0],
                ['category' => $profitName, 'type' => 'income', 'amount' => $profit, 'own_amount' => $profit],
                ['category' => self::CAPITAL_NAME, 'type' => 'income', 'amount' => $capital, 'own_amount' => $capital],
                ['category' => 'Recuperação de despesas', 'type' => 'income', 'amount' => $recovered, 'own_amount' => 0],
            ];
        }
        $rows = $transaction->allocations->isNotEmpty()
            ? $transaction->allocations->map(fn ($part) => ['category' => $part->category?->name ?? '— sem categoria —', 'type' => $transaction->type, 'amount' => (float) $part->amount])->all()
            : [['category' => $transaction->category?->name ?? '— sem categoria —', 'type' => $transaction->type, 'amount' => (float) $transaction->amount]];
        $remaining = (int) round((float) $transaction->amount * 100);
        foreach ($rows as &$row) {
            $cents = (int) round($row['amount'] * 100);
            $own = $remaining > 0 ? BigInteger::of($ownCents)->multipliedBy($cents)->quotient($remaining)->toInt() : 0;
            $row['own_amount'] = $own / 100;
            $ownCents -= $own;
            $remaining -= $cents;
        }

        return $rows;
    }

    /** Opções do filtro Pessoa: titulares de cartão e contatos vinculados a lançamentos. */
    public function personOptions(): Collection
    {
        $options = collect();
        foreach (\App\Domains\Banking\Models\CreditCard::with('holderContact')->get() as $card) {
            if (! $card->hasHolder()) {
                continue;
            }
            $key = $card->holderKey();
            $label = match ($card->holder_type) {
                'cassio' => 'Cassio (titular)', 'luciane' => 'Luciane (titular)',
                'filho' => trim((string) $card->holder_name).' (titular)',
                'contato' => ($card->holderContact?->name ?? 'Contato').' (titular)',
                default => $key,
            };
            $options[$key] = $label;
        }
        $ids = Transaction::whereNotNull('link_contact_id')->distinct()->pluck('link_contact_id')
            ->merge(\App\Domains\Banking\Models\Receivable::pluck('contact_id'))
            ->merge(\App\Domains\Banking\Models\Loan::pluck('contact_id'))->filter()->unique();
        foreach (\App\Domains\Contacts\Models\Contact::whereIn('id', $ids)->orderBy('name')->get() as $c) {
            $options['contato:'.$c->id] ??= $c->name;
        }

        return $options->sort();
    }
}

<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Transaction;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;

/**
 * Receitas e despesas próprias. Regime de caixa: compra no cartão entra pelo pagamento da fatura.
 * Regime de competência (padrão): compra no cartão entra pela data da compra e o pagamento da fatura não conta.
 * O regime é preferência de cada usuário (users.financial_regime), escolhida em Configurações.
 * Transferências, lançamentos históricos (antes do saldo inicial datado) e testes ficam fora.
 */
class OwnFinancialService
{
    public const CASH = 'cash';

    public const ACCRUAL = 'accrual';

    public static function currentRegime(): string
    {
        $regime = auth()->user()?->financial_regime;

        return in_array($regime, [self::CASH, self::ACCRUAL], true) ? $regime : self::ACCRUAL;
    }

    public function totals(Carbon $start, Carbon $end, ?string $regime = null): array
    {
        $regime ??= self::currentRegime();
        $rows = Transaction::with(['bankAccount', 'receivableInstallments', 'receivableSettlements', 'loanSettlements', 'destinations', 'destinationPayment', 'allocations.category'])
            ->where('status', 'settled')->whereIn('type', ['income', 'expense', 'invoice_payment'])
            ->whereBetween('date', [$start, $end])->notHistorical()->get();
        $income = $expense = 0;
        foreach ($rows as $row) {
            $value = $this->ownCents($row, $regime);
            if ($row->type === 'income') {
                $income += $value;
            } else {
                $expense += $value;
            }
        }

        return ['income' => $income / 100, 'expense' => $expense / 100, 'result' => ($income - $expense) / 100];
    }

    private int|false|null $assetPurchaseId = null;

    /** Categoria "Aquisição de bem" (sem criar): lançamentos nela ficam fora do resultado e dos gráficos de despesas. */
    public function assetPurchaseCategoryId(): ?int
    {
        if ($this->assetPurchaseId === null) {
            $this->assetPurchaseId = (int) \App\Domains\Banking\Models\Category::where('system_key', \App\Domains\Banking\Models\Category::ASSET_PURCHASE_KEY)->value('id') ?: false;
        }

        return $this->assetPurchaseId ?: null;
    }

    public function isAssetPurchase(Transaction $row): bool
    {
        $id = $this->assetPurchaseCategoryId();

        return $id !== null && $row->type === 'expense' && (int) $row->category_id === $id;
    }

    /** @var array<int, int>|null lançamento → centavos fora do resultado (adiantamento aos sócios ou compra do bem) */
    private ?array $divisionOut = null;

    /**
     * Despesas do Divisões pagas pelo Cassio com lançamento no Financeiro: quanto do lançamento NÃO é despesa dele.
     * Despesa comum: o adiantamento aos sócios (total − parte dele). Compra do bem: a despesa inteira (a parte dele é
     * aquisição de bem e o resto adiantamento). Só despesas aprovadas e não excluídas.
     */
    public function divisionOutCents(Transaction $row): int
    {
        if ($this->divisionOut === null) {
            $this->divisionOut = [];
            $expenses = \App\Domains\Divisions\Models\DivisionExpense::whereNotNull('transaction_id')->where('status', 'approved')
                ->with(['shares', 'group' => fn ($q) => $q->withTrashed()->with('members')])->get();
            foreach ($expenses as $e) {
                $owner = $e->group?->ownerMember();
                if (! $owner || (int) $e->paid_by_member_id !== (int) $owner->id) {
                    continue;
                }
                $total = (int) round((float) $e->amount * 100);
                $mine = (int) round((float) $e->shares->where('member_id', $owner->id)->sum('amount') * 100);
                $this->divisionOut[(int) $e->transaction_id] = ($this->divisionOut[(int) $e->transaction_id] ?? 0) + ($e->isPurchase() ? $total : max(0, $total - $mine));
            }
        }

        return $row->type === 'expense' ? ($this->divisionOut[(int) $row->id] ?? 0) : 0;
    }

    public function ownCents(Transaction $row, ?string $regime = null): int
    {
        $regime ??= self::currentRegime();
        if ($row->bankAccount?->type === 'repasse') {
            return 0;
        }
        // Acerto do Divisões (receber ou pagar diferença): devolução, nem receita nem despesa.
        if ($row->source_type === 'division_settlement') {
            return 0;
        }
        $amount = (int) round((float) $row->amount * 100);
        if ($row->type === 'income') {
            if ($row->destinations->isNotEmpty()) {
                return (int) round(((float) $row->destinations->where('kind', 'own')->sum('amount') + $row->destinations->sum(fn ($part) => (float) $part->payments->sum('writ_amount'))) * 100);
            }
            // Devolução de principal (valores a receber / empréstimos) não é receita; juros/multa recebidos são.
            if ($row->receivableSettlements->isNotEmpty()) {
                return (int) round(((float) $row->receivableSettlements->sum('interest_amount') + (float) $row->receivableSettlements->sum('penalty_amount')) * 100);
            }
            // Venda de ativo: só o ganho realizado é receita; o custo devolvido é conversão de patrimônio em caixa.
            if ($row->source_type === \App\Domains\Investments\Models\AssetOperation::class) {
                $op = \App\Domains\Investments\Models\AssetOperation::withTrashed()->find($row->source_id);

                return $op && $op->type === 'sell' ? max(0, (int) round((float) $op->realized_pnl * 100)) : 0;
            }
            if ($row->loanSettlements->isNotEmpty()) {
                return (int) round((float) $row->loanSettlements->sum('interest_amount') * 100);
            }

            return $amount;
        }
        if ($row->type === 'expense') {
            if ($row->destinationPayment) {
                return 0;
            }
            // Empréstimo concedido: conversão de caixa em crédito a recuperar, fora do resultado.
            if ($row->source_type === \App\Domains\Banking\Models\Loan::class) {
                return 0;
            }
            // Compra de ativo: conversão de caixa em investimento, fora do resultado.
            if ($row->source_type === \App\Domains\Investments\Models\AssetOperation::class) {
                return 0;
            }
            // Aquisição de bem (categoria de sistema): conversão de caixa em patrimônio, fora do resultado.
            if ($this->isAssetPurchase($row)) {
                return 0;
            }
            // Entrada ou parcela de financiamento de bem (Opção A: parcela inteira fora do resultado).
            if (in_array($row->source_type, [
                \App\Domains\Assets\Models\PropertyFinancingInstallment::class,
                \App\Domains\Assets\Models\PropertyDownPayment::class,
            ], true)) {
                return 0;
            }
            if ($row->credit_card_invoice_id && $regime !== self::ACCRUAL) {
                return 0;
            }

            // Divisões: só a parte do Cassio é despesa; o resto é adiantamento aos sócios (a receber), fora do resultado.
            return max(0, $amount - $row->receivableInstallments->sum(fn ($part) => $part->amountCents()) - $this->divisionOutCents($row));
        }
        if ($row->type === 'invoice_payment' && $regime === self::ACCRUAL && $row->credit_card_invoice_id) {
            return 0;
        }
        if ($row->type !== 'invoice_payment' || ! $row->credit_card_invoice_id) {
            return $amount;
        }
        $invoice = $row->invoice;
        $purchases = $invoice->transactions()->with('receivableInstallments')->where('type', 'expense')->get();
        $total = (int) round((float) $purchases->sum('amount') * 100);
        if ($total <= 0) {
            return 0;
        }
        $thirdParty = $purchases->sum(fn ($purchase) => $purchase->receivableInstallments->sum(fn ($part) => $part->amountCents()));
        // Compras de bem na fatura (categoria Aquisição de bem) também ficam fora do resultado no regime de caixa.
        $thirdParty += $purchases->filter(fn ($purchase) => $this->isAssetPurchase($purchase))->sum(fn ($purchase) => (int) round((float) $purchase->amount * 100));
        // Compras do Divisões na fatura: o adiantamento aos sócios também fica fora (regime de caixa).
        $thirdParty += $purchases->reject(fn ($purchase) => $this->isAssetPurchase($purchase))->sum(fn ($purchase) => $this->divisionOutCents($purchase));
        $own = max(0, $total - $thirdParty);
        // Cumulative allocation avoids rounding drift when a bill is paid in several parts.
        $before = (int) round((float) $invoice->transactions()->where('type', 'invoice_payment')->where('status', 'settled')
            ->where(fn ($q) => $q->where('date', '<', $row->date)->orWhere(fn ($q) => $q->whereDate('date', $row->date)->where('id', '<', $row->id)))->sum('amount') * 100);

        return $this->proportion(min($total, $before + $amount), $own, $total) - $this->proportion(min($total, $before), $own, $total);
    }

    private function proportion(int $paid, int $own, int $total): int
    {
        return BigInteger::of($paid)->multipliedBy($own)->plus(intdiv($total, 2))->quotient($total)->toInt();
    }
}

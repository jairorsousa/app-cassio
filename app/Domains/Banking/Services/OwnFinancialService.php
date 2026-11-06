<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Transaction;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;

/**
 * Receitas e despesas próprias. Regime de caixa (padrão): compra no cartão entra pelo pagamento da fatura.
 * Regime de competência: compra no cartão entra pela data da compra e o pagamento da fatura não conta.
 * Transferências, lançamentos históricos (antes do saldo inicial datado) e testes ficam fora.
 */
class OwnFinancialService
{
    public const CASH = 'cash';

    public const ACCRUAL = 'accrual';

    public static function currentRegime(): string
    {
        $regime = auth()->user()?->financial_regime;

        return in_array($regime, [self::CASH, self::ACCRUAL], true) ? $regime : self::CASH;
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

    public function ownCents(Transaction $row, ?string $regime = null): int
    {
        $regime ??= self::currentRegime();
        if ($row->bankAccount?->type === 'repasse') {
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
            if ($row->credit_card_invoice_id && $regime !== self::ACCRUAL) {
                return 0;
            }

            return $amount - $row->receivableInstallments->sum(fn ($part) => $part->amountCents());
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

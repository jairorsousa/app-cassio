<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function findOrCreateForPurchase(CreditCard $card, Carbon $purchaseDate): CreditCardInvoice
    {
        $reference = $purchaseDate->day <= $card->closing_day
            ? $purchaseDate->copy()
            : $purchaseDate->copy()->addMonthNoOverflow();

        return $this->findOrCreateForReference($card, $reference);
    }

    public function findOrCreateForReference(CreditCard $card, Carbon $reference): CreditCardInvoice
    {
        $referenceMonth = $reference->format('Y-m');
        $dueMonth = $card->due_day <= $card->closing_day
            ? $reference->copy()->addMonthNoOverflow()
            : $reference->copy();

        return CreditCardInvoice::firstOrCreate(
            [
                'credit_card_id' => $card->id,
                'reference_month' => $referenceMonth,
            ],
            [
                'status' => 'open',
                'total' => 0,
                'paid_amount' => 0,
                'closing_date' => Carbon::createFromDate(
                    $reference->year, $reference->month,
                    min($card->closing_day, $reference->daysInMonth)
                ),
                'due_date' => Carbon::createFromDate(
                    $dueMonth->year, $dueMonth->month,
                    min($card->due_day, $dueMonth->daysInMonth)
                ),
            ]
        );
    }

    public function recalculateTotal(CreditCardInvoice $invoice): CreditCardInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $sum = (float) $invoice->transactions()
                ->whereNull('deleted_at')
                ->where('type', 'expense')
                ->sum('amount');

            $invoice->update(['total' => $sum]);

            return $this->syncPayments($invoice->fresh());
        });
    }

    /**
     * Recalcula o pago (soma dos pagamentos liquidados) e o status armazenado.
     * Pagamento excluído/estornado volta a deixar a fatura devida.
     */
    public function syncPayments(CreditCardInvoice $invoice): CreditCardInvoice
    {
        $paidCents = (int) round((float) $invoice->transactions()->whereNull('deleted_at')
            ->where('type', 'invoice_payment')->where('status', 'settled')->sum('amount') * 100);
        $totalCents = (int) round((float) $invoice->total * 100);
        $cycleClosed = $invoice->closed_at !== null
            || in_array($invoice->status, ['closed', 'partially_paid'], true);

        $status = match (true) {
            $totalCents > 0 && $paidCents >= $totalCents => 'paid',
            ! $cycleClosed => 'open',
            $paidCents > 0 => 'partially_paid',
            default => 'closed',
        };

        $invoice->forceFill(['paid_amount' => $paidCents / 100, 'status' => $status]);
        if ($invoice->isDirty()) {
            $invoice->save();
        }

        return $invoice;
    }

    public function closeInvoice(CreditCardInvoice $invoice): CreditCardInvoice
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->status === 'open') {
                $this->recalculateTotal($invoice);
                $invoice->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                ]);
            }

            return $invoice->fresh();
        });
    }
}

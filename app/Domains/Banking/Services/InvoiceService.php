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

        $referenceMonth = $reference->format('Y-m');

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
                    $reference->year, $reference->month,
                    min($card->due_day, $reference->daysInMonth)
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

            return $invoice->fresh();
        });
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

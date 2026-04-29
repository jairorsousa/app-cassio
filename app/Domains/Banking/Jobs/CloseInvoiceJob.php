<?php

namespace App\Domains\Banking\Jobs;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CloseInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(InvoiceService $invoices): void
    {
        $today = now();
        $cards = CreditCard::active()->get();

        foreach ($cards as $card) {
            $closingDay = min($card->closing_day, $today->daysInMonth);
            if ($today->day !== $closingDay) {
                continue;
            }

            $reference = $today->copy()->format('Y-m');
            $invoice = $card->invoices()
                ->where('reference_month', $reference)
                ->where('status', 'open')
                ->first();

            if ($invoice) {
                $invoices->closeInvoice($invoice);
            }
        }
    }
}

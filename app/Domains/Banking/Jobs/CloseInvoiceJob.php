<?php

namespace App\Domains\Banking\Jobs;

use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fecha as faturas abertas cujo dia de fechamento já passou (compras no próprio dia do fechamento
 * ainda entram nela). Recupera dias em que o agendador não rodou, sem depender do dia exato.
 */
class CloseInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(InvoiceService $invoices): void
    {
        CreditCardInvoice::where('status', 'open')
            ->whereDate('closing_date', '<', now()->toDateString())
            ->whereHas('creditCard', fn ($q) => $q->where('status', true))
            ->get()
            ->each(fn (CreditCardInvoice $invoice) => $invoices->closeInvoice($invoice));
    }
}

<?php

namespace App\Domains\Banking\Observers;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TransactionObserver
{
    public function __construct(
        private InvoiceService $invoices,
        private DashboardSnapshotService $dashboard,
    ) {}

    public function updating(Transaction $transaction): void
    {
        if ($transaction->isDirty(['amount', 'type', 'date', 'status', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id'])) {
            $this->guardReceivables($transaction);
        }
    }

    public function deleting(Transaction $transaction): void
    {
        $this->guardReceivables($transaction);
    }

    private function guardReceivables(Transaction $transaction): void
    {
        if (! Schema::hasTable('receivable_installments')) {
            return;
        }
        if ($transaction->receivableInstallments()->exists() || $transaction->receivableSettlements()->exists()) {
            throw new \DomainException('Ajuste ou remova o vínculo em Valores a receber antes de alterar ou excluir este lançamento.');
        }
    }

    public function saved(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);
        $this->refreshInvoice($transaction);
        $this->scheduleDashboardRefresh();
    }

    public function deleted(Transaction $transaction): void
    {
        $this->invalidateCache($transaction);
        $this->refreshInvoice($transaction);
        $this->scheduleDashboardRefresh();
    }

    private function invalidateCache(Transaction $transaction): void
    {
        Cache::forget('banking.cashflow.'.$transaction->date->format('Y-m'));
        Cache::forget('banking.dashboard.summary');
        Cache::forget('banking.dashboard.summary.v2');
        Cache::forget('banking.dashboard.summary.v2.cash');
        Cache::forget('banking.dashboard.summary.v2.accrual');
    }

    private function scheduleDashboardRefresh(): void
    {
        $this->dashboard->invalidate();
        RefreshDashboardSnapshotJob::dispatch()->afterResponse();
    }

    private function refreshInvoice(Transaction $transaction): void
    {
        $ids = array_filter(array_unique([
            $transaction->credit_card_invoice_id,
            $transaction->getOriginal('credit_card_invoice_id'),
        ]));
        if ($ids === [] || ! in_array($transaction->type, ['expense', 'invoice_payment'], true) && ! in_array($transaction->getOriginal('type'), ['expense', 'invoice_payment'], true)) {
            return;
        }
        foreach (\App\Domains\Banking\Models\CreditCardInvoice::whereIn('id', $ids)->get() as $invoice) {
            // Total = compras (despesas) da fatura; pago = pagamentos liquidados. Ao mudar de fatura, as duas são refeitas.
            $this->invoices->recalculateTotal($invoice);
        }
    }
}

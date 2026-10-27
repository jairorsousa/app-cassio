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
    }

    private function scheduleDashboardRefresh(): void
    {
        $this->dashboard->invalidate();
        RefreshDashboardSnapshotJob::dispatch()->afterResponse();
    }

    private function refreshInvoice(Transaction $transaction): void
    {
        if ($transaction->credit_card_invoice_id && $transaction->type === 'expense') {
            $invoice = $transaction->invoice()->first();
            if ($invoice) {
                $this->invoices->recalculateTotal($invoice);
            }
        }
    }
}

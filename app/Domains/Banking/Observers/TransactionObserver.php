<?php

namespace App\Domains\Banking\Observers;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Dashboard\Jobs\RefreshDashboardSnapshotJob;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Support\Facades\Cache;

class TransactionObserver
{
    public function __construct(
        private InvoiceService $invoices,
        private DashboardSnapshotService $dashboard,
    ) {
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

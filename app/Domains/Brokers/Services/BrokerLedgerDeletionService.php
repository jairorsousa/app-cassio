<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use Illuminate\Support\Facades\DB;

class BrokerLedgerDeletionService
{
    public function __construct(
        private TransactionService $transactions,
        private BrokerCommissionService $commissions,
    ) {
    }

    /**
     * Exclui um adiantamento, desfaz compensações e remove a despesa no Banking.
     */
    public function deleteAdvance(BrokerAdvance $advance): void
    {
        DB::transaction(function () use ($advance) {
            $advance = BrokerAdvance::with('settlements')->lockForUpdate()->findOrFail($advance->id);

            $affectedCommissionIds = $advance->settlements()->pluck('commission_id')->unique()->all();

            // Settlements cascateiam no FK, mas removemos explicitamente para
            // recalcular o status das comissões afetadas.
            $advance->settlements()->delete();

            $this->deleteLinkedTransaction($this->resolveAdvanceTransaction($advance));

            $advance->delete();

            $this->resyncCommissions($affectedCommissionIds);
        });
    }

    /**
     * Exclui um repasse e reabre o saldo da comissão correspondente.
     */
    public function deletePayment(BrokerCommissionPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = BrokerCommissionPayment::with('transaction')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $commissionId = $payment->commission_id;

            $this->deleteLinkedTransaction($payment->transaction);

            $payment->delete();

            $this->resyncCommissions([$commissionId]);
        });
    }

    /**
     * Exclui uma comissão, seus repasses (e despesas) e compensações.
     * O saldo dos adiantamentos volta a ficar disponível.
     */
    public function deleteCommission(BrokerCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $commission = BrokerCommission::with('payments.transaction', 'settlements')
                ->lockForUpdate()
                ->findOrFail($commission->id);

            foreach ($commission->payments as $payment) {
                $this->deleteLinkedTransaction($payment->transaction);
            }

            // Settlements e payments caem por cascade, mas limpamos as despesas
            // antes e removemos explicitamente para manter o fluxo legível.
            $commission->payments()->delete();
            $commission->settlements()->delete();
            $commission->delete();
        });
    }

    /**
     * @param  array<int, int|string|null>  $commissionIds
     */
    private function resyncCommissions(array $commissionIds): void
    {
        $ids = array_values(array_unique(array_filter($commissionIds)));

        if ($ids === []) {
            return;
        }

        BrokerCommission::whereIn('id', $ids)
            ->get()
            ->each(fn (BrokerCommission $commission) => $this->commissions->syncStatus($commission));
    }

    private function deleteLinkedTransaction(?Transaction $transaction): void
    {
        if (! $transaction) {
            return;
        }

        $this->transactions->deleteGenerated($transaction);
    }

    private function resolveAdvanceTransaction(BrokerAdvance $advance): ?Transaction
    {
        if ($advance->transaction_id) {
            return Transaction::find($advance->transaction_id);
        }

        $linkedPaymentIds = BrokerCommissionPayment::query()
            ->where('broker_id', $advance->broker_id)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id');

        $linkedAdvanceIds = BrokerAdvance::query()
            ->where('broker_id', $advance->broker_id)
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id');

        $excludedIds = $linkedPaymentIds->merge($linkedAdvanceIds)->unique()->filter()->all();

        return Transaction::query()
            ->where('source_type', Broker::class)
            ->where('source_id', $advance->broker_id)
            ->where('type', 'expense')
            ->whereDate('date', $advance->date->toDateString())
            ->where('amount', $advance->amount)
            ->where('description', 'like', 'Adiantamento corretor%')
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('id')
            ->first();
    }
}

<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BrokerAdvanceService
{
    public function __construct(
        private BrokerCommissionService $commissions,
        private TransactionService $transactions,
    ) {}

    /**
     * Registra um adiantamento ao corretor.
     *
     * Se houver saldo de comissão a pagar, o adiantamento é compensado
     * automaticamente (settlement), reduzindo o saldo a pagar — sem gerar repasse.
     *
     * @param  array{broker_id: int, date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string}  $data
     * @return array{advance: BrokerAdvance, repassed_amount: float, advance_amount: float, settled_amount: float, payments: Collection}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $broker = Broker::lockForUpdate()->findOrFail($data['broker_id']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new \DomainException('O valor do adiantamento deve ser maior que zero.');
            }

            $advance = BrokerAdvance::create([
                'broker_id' => $broker->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            BrokerAdvancePaid::dispatch($advance->load('broker'));

            $settledAmount = $this->commissions->settleAdvanceWithCommissions($advance->fresh());

            return [
                'advance' => $advance->fresh(),
                'repassed_amount' => 0.0,
                'advance_amount' => $amount,
                'settled_amount' => $settledAmount,
                'payments' => collect(),
            ];
        });
    }

    /**
     * Atualiza um adiantamento, sua despesa no Banking e refaz as compensações.
     *
     * @param  array{date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string}  $data
     */
    public function update(BrokerAdvance $advance, array $data): BrokerAdvance
    {
        return DB::transaction(function () use ($advance, $data) {
            $advance = BrokerAdvance::with('broker', 'transaction', 'settlements')
                ->lockForUpdate()
                ->findOrFail($advance->id);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new \DomainException('O valor do adiantamento deve ser maior que zero.');
            }

            $transaction = $advance->transaction ?: $this->resolveLegacyTransaction($advance);
            $affectedCommissionIds = $advance->settlements()->pluck('commission_id')->unique()->all();

            $advance->settlements()->delete();
            BrokerCommission::whereIn('id', $affectedCommissionIds)
                ->get()
                ->each(fn (BrokerCommission $commission) => $this->commissions->syncStatus($commission));

            $advance->update([
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($transaction) {
                $this->transactions->updateGenerated($transaction, [
                    'date' => $advance->date->toDateString(),
                    'amount' => $advance->amount,
                    'description' => 'Adiantamento corretor · '.$advance->broker->name,
                    'bank_account_id' => $advance->bank_account_id,
                ]);

                if (! $advance->transaction_id) {
                    $advance->update(['transaction_id' => $transaction->id]);
                }
            } else {
                BrokerAdvancePaid::dispatch($advance->fresh()->load('broker'));
            }

            $this->commissions->settleAdvanceWithCommissions($advance->fresh());

            return $advance->fresh(['bankAccount', 'transaction', 'settlements']);
        });
    }

    public static function statusMessage(array $result): string
    {
        $settled = (float) ($result['settled_amount'] ?? 0);
        $advanced = (float) ($result['advance_amount'] ?? 0);
        $remaining = round(max($advanced - $settled, 0), 2);

        if ($settled > 0 && $remaining > 0) {
            return 'Adiantamento de R$ '.number_format($advanced, 2, ',', '.')
                .' registrado. Compensado R$ '.number_format($settled, 2, ',', '.')
                .' no saldo a pagar; restante de R$ '.number_format($remaining, 2, ',', '.')
                .' em aberto.';
        }

        if ($settled > 0) {
            return 'Adiantamento de R$ '.number_format($advanced, 2, ',', '.')
                .' registrado e totalmente compensado no saldo a pagar.';
        }

        return 'Adiantamento registrado.';
    }

    /**
     * Localiza a despesa de adiantamentos antigos, criados antes do vínculo por transaction_id.
     */
    private function resolveLegacyTransaction(BrokerAdvance $advance): ?Transaction
    {
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

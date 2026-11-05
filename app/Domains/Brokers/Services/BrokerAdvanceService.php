<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BrokerAdvanceService
{
    public function __construct(
        private BrokerCommissionService $commissions,
        private TransactionService $transactions,
        private BrokerProcessExpenseService $processExpenses,
    ) {}

    /**
     * Registra um adiantamento pessoal ao corretor.
     * Adiantamentos de despesa de processo devem usar BrokerProcessExpenseService.
     *
     * @param  array{broker_id: int, date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string, process_ref?: ?string, writ_id?: ?int, contact_id?: ?int, kind?: string}  $data
     * @return array{advance: BrokerAdvance, repassed_amount: float, advance_amount: float, settled_amount: float, payments: Collection}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $broker = Broker::lockForUpdate()->findOrFail($data['broker_id']);
            $amount = round((float) $data['amount'], 2);
            $kind = $data['kind'] ?? BrokerAdvance::KIND_PERSONAL;

            if ($amount <= 0) {
                throw new \DomainException('O valor do adiantamento deve ser maior que zero.');
            }

            if ($kind === BrokerAdvance::KIND_PROCESS_EXPENSE) {
                throw new \DomainException('Use a aba Despesas de processos para lançar despesa de processo.');
            }

            $advance = BrokerAdvance::create([
                'broker_id' => $broker->id,
                'kind' => BrokerAdvance::KIND_PERSONAL,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'process_ref' => $data['process_ref'] ?? null,
                'writ_id' => $data['writ_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
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
     * @param  array{date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string, process_ref?: ?string, writ_id?: ?int, contact_id?: ?int}  $data
     */
    public function update(BrokerAdvance $advance, array $data): BrokerAdvance
    {
        return DB::transaction(function () use ($advance, $data) {
            $advance = BrokerAdvance::with('broker', 'transaction', 'settlements')
                ->lockForUpdate()
                ->findOrFail($advance->id);
            if ($advance->settlements()->whereHas('commission', fn ($q) => $q->where('manual_advances', true))->exists()) {
                throw new \DomainException('Desfaça a compensação no recebimento de processo antes de alterar ou excluir este adiantamento.');
            }
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
                'process_ref' => $data['process_ref'] ?? $advance->process_ref,
                'writ_id' => array_key_exists('writ_id', $data) ? $data['writ_id'] : $advance->writ_id,
                'contact_id' => array_key_exists('contact_id', $data) ? $data['contact_id'] : $advance->contact_id,
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

    /**
     * Reclassifica um adiantamento pessoal (sem compensações) como despesa de processo.
     * Não recalcula histórico: só move o registro após confirmação.
     */
    public function reclassifyAsProcessExpense(BrokerAdvance $advance, string $description, ?string $processRef = null, ?int $contactId = null, ?int $writId = null): BrokerProcessExpense
    {
        return DB::transaction(function () use ($advance, $description, $processRef, $contactId, $writId) {
            $advance = BrokerAdvance::with('broker', 'transaction', 'settlements')->lockForUpdate()->findOrFail($advance->id);

            if (! $advance->isPersonal()) {
                throw new \DomainException('Este lançamento já não é um adiantamento pessoal.');
            }
            if ($advance->settlements()->exists()) {
                throw new \DomainException('Desfaça as compensações deste adiantamento antes de reclassificá-lo.');
            }
            if (! $advance->bank_account_id) {
                throw new \DomainException('Informe a conta de origem no adiantamento antes de reclassificar.');
            }

            $broker = Broker::lockForUpdate()->findOrFail($advance->broker_id);
            $description = trim($description) !== '' ? trim($description) : (trim((string) $advance->notes) ?: 'Despesa de processo reclassificada');

            // Remove a despesa bancária do adiantamento; a despesa de processo cria a própria.
            if ($advance->transaction) {
                $this->transactions->deleteGenerated($advance->transaction);
            } else {
                $legacy = $this->resolveLegacyTransaction($advance);
                if ($legacy) {
                    $this->transactions->deleteGenerated($legacy);
                }
            }

            $expense = $this->processExpenses->save($broker, [
                'date' => $advance->date->toDateString(),
                'amount' => (string) $advance->amount,
                'description' => mb_substr($description, 0, 200),
                'bank_account_id' => $advance->bank_account_id,
                'process_ref' => $processRef ?: $advance->process_ref,
                'writ_id' => $writId ?: $advance->writ_id,
                'contact_id' => $contactId ?: $advance->contact_id,
            ]);

            $advance->delete();

            return $expense;
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

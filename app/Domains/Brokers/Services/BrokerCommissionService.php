<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionRule;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use Illuminate\Support\Facades\DB;

class BrokerCommissionService
{
    public function __construct(private TransactionService $transactions)
    {
    }

    /**
     * Registra uma comissão calculando automaticamente o valor baseado na regra vigente.
     *
     * @param  array{broker_id: int, case_type_id: int, base_amount: float, reference_date: string, bank_account_id?: int, notes?: string}  $data
     */
    public function register(array $data): BrokerCommission
    {
        $rule = BrokerCommissionRule::where('broker_id', $data['broker_id'])
            ->where('case_type_id', $data['case_type_id'])
            ->validOn($data['reference_date'])
            ->orderByDesc('valid_from')
            ->first();

        if (! $rule) {
            throw new \DomainException('Nenhuma regra de comissão vigente para este corretor e tipo de caso.');
        }

        $baseAmount = (float) $data['base_amount'];
        $percentage = (float) $rule->percentage;
        $commissionAmount = round($baseAmount * ($percentage / 100), 2);

        return BrokerCommission::create([
            'broker_id' => $data['broker_id'],
            'case_type_id' => $data['case_type_id'],
            'base_amount' => $baseAmount,
            'percentage_applied' => $percentage,
            'commission_amount' => $commissionAmount,
            'status' => 'pending',
            'reference_date' => $data['reference_date'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Registra uma comissão pelo valor final informado.
     *
     * @param  array{broker_id: int, case_type_id: int, commission_amount: float, reference_date: string, bank_account_id?: int, notes?: string}  $data
     */
    public function registerFixedAmount(array $data): BrokerCommission
    {
        $commissionAmount = round((float) $data['commission_amount'], 2);

        return BrokerCommission::create([
            'broker_id' => $data['broker_id'],
            'case_type_id' => $data['case_type_id'],
            'base_amount' => $commissionAmount,
            'percentage_applied' => 0,
            'commission_amount' => $commissionAmount,
            'status' => 'pending',
            'reference_date' => $data['reference_date'],
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Compensa adiantamentos pendentes contra uma comissão.
     * Retorna o valor total compensado.
     */
    public function settleWithAdvances(BrokerCommission $commission): float
    {
        return DB::transaction(function () use ($commission) {
            $remaining = $commission->remainingAmount();
            if ($remaining <= 0) {
                return 0.0;
            }

            // Buscar adiantamentos do mesmo broker com saldo restante, mais antigos primeiro
            $advances = BrokerAdvance::where('broker_id', $commission->broker_id)
                ->orderBy('date')
                ->get();

            $totalSettled = 0.0;

            foreach ($advances as $advance) {
                if ($remaining <= 0) {
                    break;
                }

                $advanceRemaining = $advance->remainingBalance();
                if ($advanceRemaining <= 0) {
                    continue;
                }

                $offset = min($remaining, $advanceRemaining);

                BrokerCommissionSettlement::create([
                    'commission_id' => $commission->id,
                    'advance_id' => $advance->id,
                    'amount_offset' => $offset,
                    'settled_at' => now(),
                ]);

                $totalSettled += $offset;
                $remaining -= $offset;
            }

            $this->syncStatus($commission->fresh());

            return round($totalSettled, 2);
        });
    }

    /**
     * Registra um repasse em dinheiro ao corretor.
     */
    public function payAmount(
        BrokerCommission $commission,
        float $amount,
        ?string $paidAt = null,
        ?int $bankAccountId = null,
        ?string $notes = null,
    ): BrokerCommissionPayment {
        return DB::transaction(function () use ($commission, $amount, $paidAt, $bankAccountId, $notes) {
            $commission = BrokerCommission::with('broker', 'caseType')
                ->lockForUpdate()
                ->findOrFail($commission->id);

            $amount = round($amount, 2);
            $remaining = $commission->remainingAmount();

            if ($remaining <= 0) {
                throw new \DomainException('Esta comissão não possui saldo a repassar.');
            }

            if ($amount <= 0) {
                throw new \DomainException('O valor do repasse deve ser maior que zero.');
            }

            if ($amount > $remaining) {
                throw new \DomainException('O valor do repasse não pode ser maior que o saldo a pagar.');
            }

            $payment = BrokerCommissionPayment::create([
                'broker_id' => $commission->broker_id,
                'commission_id' => $commission->id,
                'paid_at' => $paidAt ?: now()->toDateString(),
                'amount' => $amount,
                'bank_account_id' => $bankAccountId ?: $commission->bank_account_id,
                'notes' => $notes,
            ]);

            $transaction = $this->transactions->create([
                'type' => 'expense',
                'date' => $payment->paid_at->toDateString(),
                'amount' => $payment->amount,
                'description' => 'Repasse comissão corretor · '.$commission->broker->name
                    .' · '.$commission->caseType->name,
                'status' => 'settled',
                'bank_account_id' => $payment->bank_account_id,
                'source_type' => Broker::class,
                'source_id' => $commission->broker_id,
            ]);

            $payment->update(['transaction_id' => $transaction->id]);
            $this->syncStatus($commission->fresh());

            return $payment->fresh('transaction');
        });
    }

    /**
     * Quita o saldo restante da comissão.
     */
    public function pay(BrokerCommission $commission): void
    {
        $this->payAmount(
            $commission,
            $commission->remainingAmount(),
            now()->toDateString(),
            $commission->bank_account_id,
            $commission->notes,
        );
    }

    private function syncStatus(BrokerCommission $commission): void
    {
        $paidOrSettled = $commission->settledAmount() + $commission->paidAmount();
        $remaining = $commission->remainingAmount();

        $commission->update([
            'status' => $remaining <= 0
                ? 'paid'
                : ($paidOrSettled > 0 ? 'partially_paid' : 'pending'),
        ]);
    }
}

<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Events\BrokerCommissionPaid;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionRule;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use Illuminate\Support\Facades\DB;

class BrokerCommissionService
{
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

            // Atualizar status da comissão
            $newRemaining = $commission->fresh()->remainingAmount();
            if ($newRemaining <= 0) {
                $commission->update(['status' => 'paid']);
            } elseif ($totalSettled > 0) {
                $commission->update(['status' => 'partially_paid']);
            }

            return round($totalSettled, 2);
        });
    }

    /**
     * Marca comissão como paga (sem compensação) e dispara evento.
     */
    public function pay(BrokerCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $commission->update(['status' => 'paid']);
            BrokerCommissionPaid::dispatch($commission->fresh()->load('broker', 'caseType'));
        });
    }
}

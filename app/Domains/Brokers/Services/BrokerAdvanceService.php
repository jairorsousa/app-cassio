<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use Illuminate\Support\Facades\DB;

class BrokerAdvanceService
{
    public function __construct(private BrokerCommissionService $commissions)
    {
    }

    /**
     * Registra um adiantamento ao corretor.
     *
     * Se houver saldo de comissão a pagar, o adiantamento é compensado
     * automaticamente (settlement), reduzindo o saldo a pagar — sem gerar repasse.
     *
     * @param  array{broker_id: int, date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string}  $data
     * @return array{advance: BrokerAdvance, repassed_amount: float, advance_amount: float, settled_amount: float, payments: \Illuminate\Support\Collection}
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
}

<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use Illuminate\Support\Facades\DB;

class BrokerAdvanceService
{
    /**
     * Registra um adiantamento puro ao corretor.
     * Não converte o valor em repasse de comissão — isso deve ser feito
     * explicitamente pelo fluxo de repasse.
     *
     * @param  array{broker_id: int, date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string}  $data
     * @return array{advance: BrokerAdvance, repassed_amount: float, advance_amount: float, payments: \Illuminate\Support\Collection}
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

            return [
                'advance' => $advance,
                'repassed_amount' => 0.0,
                'advance_amount' => $amount,
                'payments' => collect(),
            ];
        });
    }

    public static function statusMessage(array $result): string
    {
        return 'Adiantamento registrado.';
    }
}

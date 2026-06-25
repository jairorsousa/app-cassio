<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BrokerAdvanceService
{
    public function __construct(private BrokerCommissionService $commissions)
    {
    }

    /**
     * Registra um pagamento ao corretor, abatendo primeiro o saldo de comissões
     * em aberto como repasse e registrando o excedente como adiantamento.
     *
     * @param  array{broker_id: int, date: string, amount: float, payment_method?: ?string, bank_account_id?: ?int, notes?: ?string}  $data
     * @return array{advance: ?BrokerAdvance, repassed_amount: float, advance_amount: float, payments: Collection<int, BrokerCommissionPayment>}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $broker = Broker::lockForUpdate()->findOrFail($data['broker_id']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new \DomainException('O valor do adiantamento deve ser maior que zero.');
            }

            $pendingBalance = $this->pendingCommissionBalance($broker);
            $repasseAmount = round(min($amount, $pendingBalance), 2);
            $advanceAmount = round($amount - $repasseAmount, 2);

            $payments = collect();

            if ($repasseAmount > 0) {
                $payments = $this->payPendingCommissions(
                    $broker,
                    $repasseAmount,
                    $data['date'],
                    $data['bank_account_id'] ?? null,
                    $data['notes'] ?? null,
                );
            }

            $advance = null;

            if ($advanceAmount > 0) {
                $advance = BrokerAdvance::create([
                    'broker_id' => $broker->id,
                    'date' => $data['date'],
                    'amount' => $advanceAmount,
                    'payment_method' => $data['payment_method'] ?? null,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                BrokerAdvancePaid::dispatch($advance->load('broker'));
            }

            return [
                'advance' => $advance,
                'repassed_amount' => $repasseAmount,
                'advance_amount' => $advanceAmount,
                'payments' => $payments,
            ];
        });
    }

    public static function statusMessage(array $result): string
    {
        $repassed = $result['repassed_amount'];
        $advanced = $result['advance_amount'];

        if ($repassed > 0 && $advanced > 0) {
            return 'Repasse de R$ '.number_format($repassed, 2, ',', '.')
                .' registrado e adiantamento de R$ '.number_format($advanced, 2, ',', '.').'.';
        }

        if ($repassed > 0) {
            return 'Repasse de R$ '.number_format($repassed, 2, ',', '.').' registrado.';
        }

        return 'Adiantamento registrado.';
    }

    private function pendingCommissionBalance(Broker $broker): float
    {
        return round((float) $broker->commissions()
            ->with('settlements', 'payments')
            ->get()
            ->sum(fn (BrokerCommission $commission) => $commission->remainingAmount()), 2);
    }

    /**
     * @return Collection<int, BrokerCommissionPayment>
     */
    private function payPendingCommissions(
        Broker $broker,
        float $amount,
        string $paidAt,
        ?int $bankAccountId,
        ?string $notes,
    ): Collection {
        $remaining = $amount;
        $payments = collect();

        $commissions = $broker->commissions()
            ->with('settlements', 'payments')
            ->orderBy('reference_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (BrokerCommission $commission) => $commission->remainingAmount() > 0);

        foreach ($commissions as $commission) {
            if ($remaining <= 0) {
                break;
            }

            $paymentAmount = round(min($remaining, $commission->remainingAmount()), 2);

            $payments->push($this->commissions->payAmount(
                $commission,
                $paymentAmount,
                $paidAt,
                $bankAccountId,
                $notes,
            ));

            $remaining = round($remaining - $paymentAmount, 2);
        }

        return $payments;
    }
}
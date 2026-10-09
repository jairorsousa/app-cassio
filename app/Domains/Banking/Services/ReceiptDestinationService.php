<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerLedgerDeletionService;
use Illuminate\Support\Facades\DB;

class ReceiptDestinationService
{
    public function eligible(Transaction $receipt): void
    {
        if ($receipt->type !== 'income' || $receipt->status !== 'settled' || ! $receipt->bank_account_id || $receipt->isReadOnly() || $receipt->trashed()) {
            throw new \DomainException('Selecione uma receita recebida em conta bancária para definir a destinação.');
        }
    }

    public function cents(string $amount, bool $allowZero = false): int
    {
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount)) {
            throw new \DomainException('Informe valores válidos com até duas casas decimais.');
        }
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
        if (! $allowZero && $cents <= 0) {
            throw new \DomainException('Cada repasse deve ter valor maior que zero.');
        }
        return $cents;
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function replace(Transaction $receipt, array $rows): void
    {
        DB::transaction(function () use ($receipt, $rows) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $this->eligible($receipt);
            if (count($rows) < 2 || count($rows) > 20) {
                throw new \DomainException('Informe de 2 a 20 partes para o recebimento.');
            }
            $sum = $own = 0;
            foreach ($rows as $row) {
                if (! in_array($row['kind'] ?? '', ['client', 'broker', 'office', 'own'], true)) {
                    throw new \DomainException('Selecione uma destinação válida para cada parte.');
                }
                $sum += $this->cents((string) ($row['amount'] ?? ''), $row['kind'] === 'own');
                $own += $row['kind'] === 'own' ? 1 : 0;
                if ($row['kind'] !== 'broker' && $row['kind'] !== 'own' && trim($row['beneficiary'] ?? '') === '') {
                    throw new \DomainException('Informe o nome do cliente ou escritório beneficiário.');
                }
            }
            if ($own !== 1 || $sum !== $this->cents((string) $receipt->amount)) {
                throw new \DomainException('Inclua uma única parte própria e distribua exatamente o valor recebido.');
            }
            $old = $receipt->destinations()->with('commission', 'payments.transaction')->get();
            $this->guardCommissionPayments($old);
            if ($old->contains(fn ($part) => $part->paidCents() > 0 || $part->payments->isNotEmpty())) {
                throw new \DomainException('Desfaça os repasses ou compensações antes de alterar a destinação.');
            }
            // Valida e bloqueia comissões existentes antes de desfazer a divisão anterior.
            $commissionIds = [];
            $reusable = $old->where('commission_created', true)->pluck('commission_id')->all();
            foreach ($rows as $row) {
                if ($row['kind'] !== 'broker') {
                    continue;
                }
                $broker = Broker::active()->find($row['broker_id'] ?? null);
                if (! $broker) {
                    throw new \DomainException('Selecione um corretor ativo.');
                }
                if (! empty($row['commission_id'])) {
                    $commission = BrokerCommission::lockForUpdate()->findOrFail($row['commission_id']);
                    if ($commission->broker_id !== $broker->id || $this->cents((string) $commission->commission_amount) !== $this->cents((string) $row['amount'])) {
                        throw new \DomainException('A comissão vinculada deve pertencer ao corretor e ter o mesmo valor da parte.');
                    }
                    if (in_array($commission->id, $commissionIds, true) || ReceiptDestination::where('commission_id', $commission->id)->where('receipt_id', '!=', $receipt->id)->exists()) {
                        throw new \DomainException('Esta comissão já está vinculada a outro recebimento ou parte.');
                    }
                    $commissionIds[] = $commission->id;
                } elseif (! CaseType::active()->whereKey($row['case_type_id'] ?? null)->exists()) {
                    throw new \DomainException('Selecione o tipo de caso para criar a comissão do corretor.');
                }
            }
            $receipt->destinations()->delete();
            foreach ($old->where('commission_created', true) as $part) {
                if (! in_array($part->commission_id, $commissionIds, true)) {
                    app(BrokerLedgerDeletionService::class)->deleteCommission($part->commission);
                }
            }
            foreach ($rows as $row) {
                $commission = null;
                $created = false;
                $beneficiary = trim($row['beneficiary'] ?? '');
                if ($row['kind'] === 'broker') {
                    $broker = Broker::findOrFail($row['broker_id']);
                    $beneficiary = $broker->name;
                    if (! empty($row['commission_id'])) {
                        $commission = BrokerCommission::findOrFail($row['commission_id']);
                        $created = in_array($commission->id, $reusable, true);
                    } else {
                        $commission = app(BrokerCommissionService::class)->registerFixedAmount([
                            'broker_id' => $broker->id, 'case_type_id' => $row['case_type_id'],
                            'commission_amount' => (float) $row['amount'], 'reference_date' => $receipt->date->format('Y-m-d'),
                            'name' => mb_substr($receipt->description, 0, 200), 'bank_account_id' => $receipt->bank_account_id,
                            'notes' => 'Destinação do recebimento #'.$receipt->id,
                        ]);
                        app(BrokerCommissionService::class)->settleWithAdvances($commission);
                        $created = true;
                    }
                }
                $receipt->destinations()->create([
                    'kind' => $row['kind'], 'beneficiary' => $row['kind'] === 'own' ? 'Minha parte' : $beneficiary,
                    'amount' => $this->money($this->cents((string) $row['amount'], true)),
                    'commission_id' => $commission?->id, 'commission_created' => $created,
                ]);
            }
            $receipt->touch();
        });
    }

    public function clear(Transaction $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $receipt = Transaction::lockForUpdate()->findOrFail($receipt->id);
            $parts = $receipt->destinations()->with('commission', 'payments.transaction')->get();
            $this->guardCommissionPayments($parts);
            if ($parts->contains(fn ($part) => $part->paidCents() > 0 || $part->payments->isNotEmpty())) {
                throw new \DomainException('Desfaça os repasses ou compensações antes de remover a destinação.');
            }
            $receipt->destinations()->delete();
            foreach ($parts->where('commission_created', true) as $part) {
                app(BrokerLedgerDeletionService::class)->deleteCommission($part->commission);
            }
            $receipt->touch();
        });
    }

    public function pay(ReceiptDestination $part, string $amount, string $date, int $accountId, ?int $existingTransactionId = null): void
    {
        DB::transaction(function () use ($part, $amount, $date, $accountId, $existingTransactionId) {
            $receipt = Transaction::lockForUpdate()->findOrFail($part->receipt_id);
            $this->eligible($receipt);
            $part = ReceiptDestination::lockForUpdate()->findOrFail($part->id);
            if ($part->kind === 'own') {
                throw new \DomainException('A parte própria não gera repasse.');
            }
            if ($part->kind === 'broker') {
                $part->setRelation('commission', BrokerCommission::lockForUpdate()->findOrFail($part->commission_id));
            }
            $account = BankAccount::active()->find($accountId);
            if (! $account) {
                throw new \DomainException('Selecione uma conta bancária ativa para o repasse.');
            }
            if ($existingTransactionId) {
                $transaction = Transaction::lockForUpdate()->findOrFail($existingTransactionId);
                if ($transaction->type !== 'expense' || $transaction->status !== 'settled' || $transaction->isReadOnly() || $transaction->bank_account_id !== $accountId || $transaction->credit_card_id || $transaction->destinationPayment()->exists() || BrokerCommissionPayment::where('transaction_id', $transaction->id)->exists()) {
                    throw new \DomainException('Selecione uma despesa paga nesta conta e ainda não vinculada a outro repasse.');
                }
                $cents = $this->cents((string) $transaction->amount);
            } else {
                $cents = $this->cents($amount);
            }
            if ($cents > $part->remainingCents()) {
                throw new \DomainException('O repasse não pode ultrapassar o saldo desta parte.');
            }
            if ($part->kind === 'broker') {
                if ($existingTransactionId) {
                    BrokerCommissionPayment::create([
                        'broker_id' => $part->commission->broker_id, 'commission_id' => $part->commission_id,
                        'paid_at' => $transaction->date, 'amount' => $transaction->amount,
                        'bank_account_id' => $accountId, 'transaction_id' => $transaction->id,
                        'notes' => 'Saída vinculada pela destinação do recebimento #'.$part->receipt_id,
                    ]);
                    $part->payments()->create(['transaction_id' => $transaction->id, 'transaction_created' => false]);
                    app(BrokerCommissionService::class)->syncStatus($part->commission);
                } else {
                    app(BrokerCommissionService::class)->payAmount($part->commission, (float) $this->money($cents), $date, $accountId);
                }
            } else {
                $payment = $part->payments()->create(['transaction_id' => $existingTransactionId, 'transaction_created' => ! $existingTransactionId]);
                if (! $existingTransactionId) {
                    $transaction = app(TransactionService::class)->create([
                        'type' => 'expense', 'status' => 'settled', 'amount' => $this->money($cents), 'date' => $date,
                        'bank_account_id' => $accountId, 'description' => mb_substr('Repasse '.($part->kind === 'client' ? 'cliente' : 'escritório').' · '.$part->beneficiary, 0, 200),
                        'source_type' => ReceiptDestinationPayment::class, 'source_id' => $payment->id,
                    ]);
                    $payment->update(['transaction_id' => $transaction->id]);
                }
            }
            $part->receipt->touch();
        });
    }

    private function guardCommissionPayments($parts): void
    {
        foreach ($parts->whereNotNull('commission_id') as $part) {
            $commission = BrokerCommission::lockForUpdate()->findOrFail($part->commission_id);
            if ($commission->payments()->lockForUpdate()->exists() || $commission->settlements()->lockForUpdate()->exists()) {
                throw new \DomainException('Desfaça os repasses ou compensações antes de alterar a destinação.');
            }
        }
    }

    public function undo(ReceiptDestinationPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = ReceiptDestinationPayment::lockForUpdate()->findOrFail($payment->id);
            $receipt = $payment->destination->receipt;
            if ($payment->destination->kind === 'broker') {
                $brokerPayment = BrokerCommissionPayment::where('commission_id', $payment->destination->commission_id)->where('transaction_id', $payment->transaction_id)->firstOrFail();
                app(BrokerLedgerDeletionService::class)->deletePayment($brokerPayment);
                $receipt->touch();
                return;
            }
            $transaction = $payment->transaction;
            $created = $payment->transaction_created;
            $payment->delete();
            if ($created && $transaction) {
                app(TransactionService::class)->deleteGenerated($transaction);
            }
            $receipt->touch();
        });
    }
}

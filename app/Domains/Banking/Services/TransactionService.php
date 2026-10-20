<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function create(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $data['status'] ??= 'settled';

            return Transaction::create($data);
        });
    }

    public function update(Transaction $transaction, array $data): Transaction
    {
        if ($transaction->destinationPayment()->exists()) {
            throw new \DomainException('Desvincule este pagamento da divisão do recebimento antes de alterar o lançamento.');
        }
        if (array_key_exists('date', $data) && $transaction->destinations()->whereNotNull('allocation_transaction_id')->exists()
            && Carbon::parse($data['date'])->toDateString() !== $transaction->date->toDateString()) {
            throw new \DomainException('Remova a divisão antes de alterar a data do recebimento e suas transferências de repasse.');
        }
        if ($transaction->destinations()->exists()) {
            foreach (['amount', 'type', 'status', 'bank_account_id'] as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $transaction->$field && ($field !== 'amount' || round((float) $data[$field] * 100) !== round((float) $transaction->$field * 100))) {
                    throw new \DomainException('Remova a divisão do recebimento antes de alterar o valor, o tipo, a conta ou o status do recebimento.');
                }
            }
        }
        if ($transaction->isReadOnly()) {
            throw new \DomainException('Lançamento gerado por outro módulo é somente leitura.');
        }

        if ($transaction->allocations()->exists()) {
            if (isset($data['amount']) && round((float) $data['amount'] * 100) !== round((float) $transaction->amount * 100)) {
                throw new \DomainException('Ajuste ou remova o rateio antes de alterar o valor total.');
            }

            if (isset($data['type']) && $data['type'] !== $transaction->type) {
                throw new \DomainException('Remova o rateio antes de alterar o tipo do lançamento.');
            }

            $data['category_id'] = null;
        }

        return DB::transaction(function () use ($transaction, $data) {
            $oldInvoice = $transaction->invoice;
            $transaction->update($data);

            if ($transaction->type === 'transfer' && $transaction->related_transaction_id && array_key_exists('status', $data)) {
                $transaction->related?->update(['status' => $data['status']]);
            }

            if ($oldInvoice && $oldInvoice->id !== $transaction->credit_card_invoice_id) {
                app(InvoiceService::class)->recalculateTotal($oldInvoice);
            }

            return $transaction->fresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
        if ($transaction->destinations()->exists() || $transaction->destinationPayment()->exists()) {
            throw new \DomainException('Remova a divisão do recebimento ou desvincule o repasse antes de excluir este lançamento.');
        }
        if ($transaction->isReadOnly()) {
            throw new \DomainException('Lançamento gerado por outro módulo é somente leitura.');
        }

        DB::transaction(function () use ($transaction) {
            if ($transaction->related_transaction_id) {
                Transaction::where('id', $transaction->related_transaction_id)->delete();
            }

            if ($transaction->installment_group_id) {
                Transaction::where('installment_group_id', $transaction->installment_group_id)->delete();

                return;
            }

            $transaction->delete();
        });
    }

    /** Explicitly confirmed deletion from the financial transaction list. */
    public function deleteWithDestinations(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction = Transaction::lockForUpdate()->findOrFail($transaction->id);
            $payment = $transaction->destinationPayment()->lockForUpdate()->first();

            if ($transaction->isReadOnly() && ! ($payment && $transaction->source_type === ReceiptDestinationPayment::class)) {
                throw new \DomainException('Lançamento gerado por outro módulo é somente leitura.');
            }

            $destinations = app(ReceiptDestinationService::class);
            if ($payment) {
                $destinations->undo($payment);
            }
            if ($transaction->destinations()->exists()) {
                $destinations->removeForReceiptDeletion($transaction);
            }

            // Undoing a generated repasse may already have removed this transaction.
            $remaining = Transaction::find($transaction->id);
            if ($remaining) {
                $this->delete($remaining);
            }
        });
    }

    /**
     * Atualiza um lançamento gerado por outro domínio,
     * quando a edição parte do módulo de origem.
     */
    public function updateGenerated(Transaction $transaction, array $data): Transaction
    {
        if (! $transaction->isReadOnly()) {
            return $this->update($transaction, $data);
        }

        return DB::transaction(function () use ($transaction, $data) {
            $transaction->update($data);

            return $transaction->fresh();
        });
    }

    /**
     * Remove um lançamento gerado por outro domínio (ex.: corretores),
     * quando a exclusão parte do módulo de origem.
     */
    public function deleteGenerated(Transaction $transaction): void
    {
        if (! $transaction->isReadOnly()) {
            $this->delete($transaction);

            return;
        }

        $transaction->delete();
    }
}

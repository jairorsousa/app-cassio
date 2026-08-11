<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Transaction;
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
        if ($transaction->isReadOnly()) {
            throw new \DomainException('Lançamento gerado por outro módulo é somente leitura.');
        }

        return DB::transaction(function () use ($transaction, $data) {
            $transaction->update($data);

            return $transaction->fresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
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

<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function execute(
        BankAccount $from,
        BankAccount $to,
        float $amount,
        Carbon|string $date,
        ?string $description = null,
        ?string $notes = null,
    ): Transaction {
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Conta origem e destino devem ser diferentes.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor da transferência deve ser positivo.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $date, $description, $notes) {
            $description ??= "Transferência {$from->name} → {$to->name}";

            $out = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => -$amount,
                'description' => $description,
                'notes' => $notes,
                'status' => 'settled',
                'bank_account_id' => $from->id,
            ]);

            $in = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => $amount,
                'description' => $description,
                'notes' => $notes,
                'status' => 'settled',
                'bank_account_id' => $to->id,
                'related_transaction_id' => $out->id,
            ]);

            $out->update(['related_transaction_id' => $in->id]);

            return $out->fresh();
        });
    }
}

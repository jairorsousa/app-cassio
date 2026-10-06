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
        string $status = 'settled',
    ): Transaction {
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Conta origem e destino devem ser diferentes.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor da transferência deve ser positivo.');
        }

        if (! in_array($status, ['pending', 'settled'], true)) {
            throw new \InvalidArgumentException('Status da transferência inválido.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $date, $description, $notes, $status) {
            $outDescription = $description ?? "Transferência Saída {$from->name} → {$to->name}";
            $inDescription = $description ?? "Transferência Entrada {$from->name} → {$to->name}";

            $out = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => -$amount,
                'description' => $outDescription,
                'notes' => $notes,
                'status' => $status,
                'bank_account_id' => $from->id,
            ]);

            $in = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => $amount,
                'description' => $inDescription,
                'notes' => $notes,
                'status' => $status,
                'bank_account_id' => $to->id,
                'related_transaction_id' => $out->id,
            ]);

            $out->update(['related_transaction_id' => $in->id]);

            return $out->fresh();
        });
    }
}

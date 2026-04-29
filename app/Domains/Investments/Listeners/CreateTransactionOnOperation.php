<?php

namespace App\Domains\Investments\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Investments\Events\AssetOperationRegistered;

class CreateTransactionOnOperation
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(AssetOperationRegistered $event): void
    {
        $op = $event->operation;

        if (! $op->bank_account_id) {
            return;
        }

        $existing = $op->transactions()->exists();
        if ($existing) {
            return;
        }

        $ticker = $op->asset?->ticker ?? '—';
        $isBuy = $op->type === 'buy';

        $this->transactions->create([
            'type' => $isBuy ? 'expense' : 'income',
            'date' => $op->date,
            'amount' => $op->total,
            'description' => ($isBuy ? 'Compra ' : 'Venda ').$ticker.' · '.number_format((float) $op->quantity, 6, ',', '.').' un',
            'status' => 'settled',
            'bank_account_id' => $op->bank_account_id,
            'source_type' => $op::class,
            'source_id' => $op->id,
        ]);
    }
}

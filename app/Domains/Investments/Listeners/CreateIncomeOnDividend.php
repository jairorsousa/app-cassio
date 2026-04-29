<?php

namespace App\Domains\Investments\Listeners;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Investments\Events\DividendReceived;
use App\Domains\Investments\Models\AssetDividend;

class CreateIncomeOnDividend
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(DividendReceived $event): void
    {
        $div = $event->dividend;

        if (! $div->bank_account_id) {
            return;
        }

        $existing = $div->transactions()->exists();
        if ($existing) {
            return;
        }

        $label = AssetDividend::TYPE_LABELS[$div->type] ?? $div->type;
        $ticker = $div->asset?->ticker ?? '—';

        $this->transactions->create([
            'type' => 'income',
            'date' => $div->payment_date,
            'amount' => $div->total,
            'description' => "{$label} {$ticker}",
            'status' => 'settled',
            'bank_account_id' => $div->bank_account_id,
            'source_type' => $div::class,
            'source_id' => $div->id,
        ]);
    }
}

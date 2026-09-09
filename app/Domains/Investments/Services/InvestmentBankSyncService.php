<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;

class InvestmentBankSyncService
{
    public function sync(AssetOperation|AssetDividend $record): void
    {
        if (! $record->bank_account_id) {
            $record->transactions()->delete();

            return;
        }
        $isOperation = $record instanceof AssetOperation;
        $date = $isOperation ? $record->date : $record->payment_date;
        $label = $isOperation ? ($record->type === 'buy' ? 'Compra' : 'Venda') : AssetDividend::TYPE_LABELS[$record->type];
        $record->transactions()->updateOrCreate([], [
            'type' => $isOperation && $record->type === 'buy' ? 'expense' : 'income',
            'date' => $date, 'amount' => $record->total,
            'description' => $label.' '.$record->asset?->ticker,
            'bank_account_id' => $record->bank_account_id,
            'status' => $date->isFuture() ? 'pending' : 'settled',
        ]);
    }
}

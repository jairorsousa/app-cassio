<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;

class InvestmentBankSyncService
{
    public function sync(AssetOperation|AssetDividend $record): void
    {
        $record->loadMissing('asset');
        $isOperation = $record instanceof AssetOperation;
        $automaticLiquidity = $isOperation && $record->asset?->usesAutomaticLiquidity();
        $bankAccountId = $automaticLiquidity
            ? $record->asset?->linked_bank_account_id
            : $record->bank_account_id;

        if (! $bankAccountId) {
            $record->transactions()->delete();

            return;
        }

        $date = $isOperation ? $record->date : $record->payment_date;
        $label = $automaticLiquidity
            ? ($record->type === 'buy' ? 'Aplicação automática' : 'Resgate automático')
            : ($isOperation ? ($record->type === 'buy' ? 'Compra' : 'Venda') : AssetDividend::TYPE_LABELS[$record->type]);
        $amount = $automaticLiquidity
            ? ($record->type === 'buy' ? -(float) $record->total : (float) $record->total)
            : (float) $record->total;

        $record->transactions()->updateOrCreate([], [
            'type' => $automaticLiquidity ? 'transfer' : ($isOperation && $record->type === 'buy' ? 'expense' : 'income'),
            'date' => $date, 'amount' => $amount,
            'description' => $label.' '.$record->asset?->ticker,
            'bank_account_id' => $bankAccountId,
            'status' => $date->isFuture() ? 'pending' : 'settled',
        ]);
    }
}

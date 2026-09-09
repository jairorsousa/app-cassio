<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvestmentLedgerService
{
    public function saveOperation(array $data, ?int $id = null): AssetOperation
    {
        return DB::transaction(function () use ($data, $id) {
            $operation = $id ? AssetOperation::findOrFail($id) : new AssetOperation;
            $assetIds = array_unique(array_filter([$operation->asset_id, $data['asset_id']]));
            Asset::whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($assetIds as $assetId) {
                $this->validateHistory($assetId, $id, (int) $assetId === (int) $data['asset_id'] ? $data : null);
            }
            if ($data['total'] < 0) {
                throw ValidationException::withMessages(['fees' => 'As taxas não podem superar o valor da venda.']);
            }
            $operation->fill($data)->save();
            foreach ($assetIds as $assetId) {
                app(AssetPositionService::class)->recalculate(Asset::findOrFail($assetId));
            }

            return $operation;
        });
    }

    public function deleteOperation(int $id): void
    {
        DB::transaction(function () use ($id) {
            $operation = AssetOperation::findOrFail($id);
            Asset::whereKey($operation->asset_id)->lockForUpdate()->firstOrFail();
            $this->validateHistory($operation->asset_id, $id);
            $operation->delete();
        });
    }

    private function validateHistory(int $assetId, ?int $exclude, ?array $candidate = null): void
    {
        $rows = AssetOperation::where('asset_id', $assetId)->when($exclude, fn ($q) => $q->where('id', '!=', $exclude))->get()->map(fn ($op) => [
            'id' => $op->id, 'date' => $op->date->format('Y-m-d'), 'type' => $op->type, 'quantity' => (float) $op->quantity,
        ])->all();
        if ($candidate) {
            $rows[] = $candidate + ['id' => $exclude ?? PHP_INT_MAX];
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $quantity = 0;
        foreach ($rows as $row) {
            $quantity += $row['type'] === 'buy' ? (float) $row['quantity'] : -(float) $row['quantity'];
            if ($quantity < -0.0000001) {
                throw ValidationException::withMessages(['quantity' => 'Esta alteração deixa uma venda sem quantidade disponível. Confira as quantidades e a ordem das datas.']);
            }
        }
    }
}

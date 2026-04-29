<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Models\AssetQuote;
use Illuminate\Support\Facades\DB;

class AssetPositionService
{
    public function __construct(private AverageCostCalculator $calculator)
    {
    }

    public function recalculate(Asset $asset): AssetPosition
    {
        return DB::transaction(function () use ($asset) {
            $result = $this->calculator->recalculate($asset);

            foreach ($result['updated_operations'] as $patch) {
                AssetOperation::where('id', $patch['id'])->update(['realized_pnl' => $patch['realized_pnl']]);
            }

            $latestQuote = AssetQuote::where('asset_id', $asset->id)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

            $position = AssetPosition::updateOrCreate(
                ['asset_id' => $asset->id],
                [
                    'quantity' => $result['quantity'],
                    'average_price' => $result['average_price'],
                    'total_invested' => $result['total_invested'],
                    'realized_pnl_total' => $result['realized_pnl_total'],
                    'current_price' => $latestQuote?->price ?? $result['average_price'],
                    'recalculated_at' => now(),
                ]
            );

            return $position;
        });
    }

    public function setQuote(Asset $asset, string $date, float $price): AssetQuote
    {
        return DB::transaction(function () use ($asset, $date, $price) {
            $quote = AssetQuote::updateOrCreate(
                ['asset_id' => $asset->id, 'date' => $date],
                ['price' => $price]
            );

            $position = AssetPosition::where('asset_id', $asset->id)->first();
            if ($position) {
                $position->update([
                    'current_price' => $price,
                    'recalculated_at' => now(),
                ]);
            }

            return $quote;
        });
    }
}

<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetOperation;
use Illuminate\Support\Carbon;

class RealizedPnLCalculator
{
    /**
     * @return array{
     *   total: float,
     *   by_asset: \Illuminate\Support\Collection<string, float>,
     * }
     */
    public function forPeriod(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = AssetOperation::with('asset')
            ->where('type', 'sell')
            ->whereNotNull('realized_pnl');

        if ($from) $query->where('date', '>=', $from);
        if ($to) $query->where('date', '<=', $to);

        $sales = $query->get();

        $byAsset = $sales->groupBy(fn ($op) => $op->asset?->ticker ?? '—')
            ->map(fn ($g) => round($g->sum('realized_pnl'), 2));

        return [
            'total' => round($sales->sum('realized_pnl'), 2),
            'by_asset' => $byAsset,
        ];
    }
}

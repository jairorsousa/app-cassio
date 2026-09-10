<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetPosition;
use Illuminate\Support\Carbon;

class PortfolioProfitabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $positions = AssetPosition::with('asset.assetClass')
            ->where('quantity', '>', 0)
            ->get();

        $totalInvested = (float) $positions->sum('total_invested');
        $marketValue = (float) $positions->sum(fn ($p) => $p->marketValue());
        $unrealizedPnL = round($marketValue - $totalInvested, 2);
        $unrealizedPct = $totalInvested > 0 ? round(($unrealizedPnL / $totalInvested) * 100, 3) : 0.0;

        $byClass = $positions->groupBy(fn ($p) => $p->asset?->assetClass?->name ?? '—')
            ->map(function ($group) {
                return [
                    'invested' => round($group->sum('total_invested'), 2),
                    'market_value' => round($group->sum(fn ($p) => $p->marketValue()), 2),
                ];
            });

        $dividendsLast12 = (float) AssetDividend::whereDate('payment_date', '>=', Carbon::today()->subYear())->whereDate('payment_date', '<=', Carbon::today())
            ->sum('total');

        $yieldOnCost = $totalInvested > 0 ? round(($dividendsLast12 / $totalInvested) * 100, 3) : 0.0;

        $realizedTotal = (float) AssetPosition::sum('realized_pnl_total');
        $dividendsTotal = (float) AssetDividend::whereDate('payment_date', '<=', today())->sum('total');
        $totalReturn = round($unrealizedPnL + $dividendsTotal + $realizedTotal, 2);
        $totalReturnPct = $totalInvested > 0 ? round(($totalReturn / $totalInvested) * 100, 3) : 0.0;

        return [
            'total_invested' => round($totalInvested, 2),
            'market_value' => round($marketValue, 2),
            'unrealized_pnl' => $unrealizedPnL,
            'unrealized_pct' => $unrealizedPct,
            'realized_pnl_total' => round($realizedTotal, 2),
            'dividends_total' => round($dividendsTotal, 2),
            'dividends_12m' => round($dividendsLast12, 2),
            'yield_on_cost_12m' => $yieldOnCost,
            'total_return' => $totalReturn,
            'total_return_pct' => $totalReturnPct,
            'by_class' => $byClass,
        ];
    }
}

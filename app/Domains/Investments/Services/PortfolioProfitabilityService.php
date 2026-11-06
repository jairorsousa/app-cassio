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

        // Fallback: sem posição por operações, usa a última posição B3 confirmada (nunca soma as duas).
        $source = 'operations';
        $baseDate = null;
        $unknownPm = [];
        $knownMarket = null;
        $splitMissing = [];
        $b3MarketValue = null;
        $quotedCount = 0;
        $quoteTime = null;
        if ($positions->isEmpty()) {
            $b3 = \App\Domains\Investments\Models\B3PositionSnapshot::latestConfirmed();
            if ($b3->isNotEmpty()) {
                $source = 'b3';
                $baseDate = $b3->first()->base_date;
                $quotes = $b3->mapWithKeys(fn ($p) => [$p->id => $p->latestQuote()]);
                $value = fn ($p) => $p->currentValue($quotes[$p->id]);
                $known = $b3->filter(fn ($p) => $p->cost() !== null);
                $totalInvested = (float) $known->sum(fn ($p) => $p->cost());
                $marketValue = (float) $b3->sum($value);
                $b3MarketValue = round((float) $b3->sum('market_value'), 2);
                $quoted = $quotes->filter();
                $quotedCount = $quoted->count();
                $quoteTime = $quoted->max(fn ($q) => $q->market_time ?? $q->date);
                // Ganho em aberto só sobre ativos com PM conhecido.
                $knownMarket = (float) $known->sum($value);
                $unrealizedPnL = round($knownMarket - $totalInvested, 2);
                $unrealizedPct = $totalInvested > 0 ? round(($unrealizedPnL / $totalInvested) * 100, 3) : 0.0;
                $byClass = $b3->groupBy(fn ($p) => $p->asset?->assetClass?->name ?? '—')->map(fn ($g) => [
                    'invested' => round((float) $g->sum(fn ($p) => $p->cost() ?? 0), 2),
                    'market_value' => round((float) $g->sum($value), 2),
                ]);
                $unknownPm = $b3->filter(fn ($p) => $p->cost() === null)->pluck('ticker')->values()->all();
                $splitMissing = $b3->filter(fn ($p) => $p->quantity_custody === null && $p->quantity_lent === null)->pluck('ticker')->values()->all();
            }
        }

        $incomeEvents = \App\Domains\Investments\Models\AssetIncomeEvent::query();
        $dividendsLast12 = (float) AssetDividend::whereDate('payment_date', '>=', Carbon::today()->subYear())->whereDate('payment_date', '<=', Carbon::today())
            ->sum('total')
            + (float) (clone $incomeEvents)->whereDate('payment_date', '>=', Carbon::today()->subYear())->whereDate('payment_date', '<=', Carbon::today())->sum('net_amount');

        $yieldOnCost = $totalInvested > 0 ? round(($dividendsLast12 / $totalInvested) * 100, 3) : 0.0;

        $realizedTotal = (float) AssetPosition::sum('realized_pnl_total');
        $dividendsTotal = (float) AssetDividend::whereDate('payment_date', '<=', today())->sum('total')
            + (float) (clone $incomeEvents)->whereDate('payment_date', '<=', today())->sum('net_amount');
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
            // Resultado só é "total" quando todo PM é conhecido; senão é parcial (capital com PM + proventos).
            'result_partial' => $unknownPm !== [],
            'known_cost_market_value' => round($knownMarket ?? $marketValue, 2),
            'unknown_cost_market_value' => round($marketValue - ($knownMarket ?? $marketValue), 2),
            'source' => $source,
            'base_date' => $baseDate,
            'unknown_average_price' => $unknownPm,
            'custody_split_missing' => $splitMissing,
            'b3_market_value' => $b3MarketValue,
            'quoted_count' => $quotedCount,
            'quote_time' => $quoteTime,
        ];
    }
}

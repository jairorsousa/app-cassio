<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetQuote;
use Illuminate\Support\Collection;

class InvestmentAnalyticsService
{
    /**
     * @return list<array{key: string, label: string, date: string, market_value: float, invested: float}>
     */
    public function portfolioEvolution(int $months = 12): array
    {
        $start = today()->startOfMonth()->subMonths($months - 1);
        $operations = AssetOperation::query()
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy('asset_id');
        $quotes = AssetQuote::query()
            ->orderBy('date')
            ->get()
            ->groupBy('asset_id');

        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $asOf = $month->copy()->endOfMonth();
            if ($asOf->gt(today())) {
                $asOf = today();
            }
            $asOfDate = $asOf->toDateString();
            $snapshot = $this->snapshotAsOf($operations, $quotes, $asOfDate);
            $rows[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->locale('pt_BR')->translatedFormat('M/y'),
                'date' => $asOfDate,
                'market_value' => $snapshot['market_value'],
                'invested' => $snapshot['invested'],
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int|string, Collection<int, AssetOperation>>  $operations
     * @param  Collection<int|string, Collection<int, AssetQuote>>  $quotes
     * @return array{market_value: float, invested: float}
     */
    private function snapshotAsOf(Collection $operations, Collection $quotes, string $asOfDate): array
    {
        $market = 0.0;
        $invested = 0.0;

        foreach ($operations as $assetId => $ops) {
            $quantity = 0.0;
            $cost = 0.0;
            foreach ($ops as $op) {
                if ($op->date->toDateString() > $asOfDate) {
                    break;
                }
                $qty = (float) $op->quantity;
                if ($op->type === 'buy') {
                    $cost += (float) $op->total;
                    $quantity += $qty;
                } else {
                    $avg = $quantity > 0 ? $cost / $quantity : 0.0;
                    $cost -= $avg * $qty;
                    $quantity -= $qty;
                    if ($quantity <= 1e-6) {
                        $quantity = 0.0;
                        $cost = 0.0;
                    }
                }
            }

            if ($quantity <= 0) {
                continue;
            }

            $average = $cost / $quantity;
            $price = $this->priceAsOf($quotes->get($assetId, collect()), $asOfDate, $average);
            $market += $quantity * $price;
            $invested += $cost;
        }

        return [
            'market_value' => round($market, 2),
            'invested' => round(max(0, $invested), 2),
        ];
    }

    /**
     * @param  Collection<int, AssetQuote>  $quotes
     */
    private function priceAsOf(Collection $quotes, string $asOfDate, float $fallback): float
    {
        $quote = $quotes->last(fn (AssetQuote $quote) => $quote->date->toDateString() <= $asOfDate);

        return $quote ? (float) $quote->price : $fallback;
    }

    public function monthlyCashflow(int $months = 12): array
    {
        $start = today()->startOfMonth()->subMonths($months - 1);
        $end = today()->format('Y-m-d');
        $operations = AssetOperation::whereDate('date', '>=', $start)->whereDate('date', '<=', $end)->get()->groupBy(fn ($op) => $op->date->format('Y-m'));
        $dividends = AssetDividend::whereDate('payment_date', '>=', $start)->whereDate('payment_date', '<=', $end)->get()->groupBy(fn ($d) => $d->payment_date->format('Y-m'));
        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $date = $start->copy()->addMonths($i);
            $ops = $operations->get($date->format('Y-m'), collect());
            $rows[] = [
                'label' => $date->locale('pt_BR')->translatedFormat('M/y'),
                'buy' => round($ops->where('type', 'buy')->sum('total'), 2),
                'sell' => round($ops->where('type', 'sell')->sum('total'), 2),
                'income' => round($dividends->get($date->format('Y-m'), collect())->sum('total'), 2),
            ];
        }

        return $rows;
    }

    public function period(string $from, string $to): array
    {
        $operations = AssetOperation::with('asset')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $dividends = AssetDividend::with('asset')->whereDate('payment_date', '>=', $from)->whereDate('payment_date', '<=', $to)->whereDate('payment_date', '<=', today())->get();
        $ids = $operations->pluck('asset_id')->merge($dividends->pluck('asset_id'))->unique();
        $rows = $ids->map(function ($id) use ($operations, $dividends) {
            $ops = $operations->where('asset_id', $id);
            $divs = $dividends->where('asset_id', $id);
            $realized = $ops->where('type', 'sell')->sum('realized_pnl');
            $income = $divs->sum('total');

            return [
                'ticker' => ($ops->first()?->asset ?? $divs->first()?->asset)?->ticker ?? 'Ativo removido',
                'buys' => $ops->where('type', 'buy')->sum('total'),
                'sales' => $ops->where('type', 'sell')->sum('total'),
                'fees' => $ops->sum('fees'),
                'realized' => $realized, 'income' => $income, 'result' => $realized + $income,
            ];
        })->sortByDesc('result')->values();

        return ['rows' => $rows, 'buys' => $rows->sum('buys'), 'sales' => $rows->sum('sales'), 'fees' => $rows->sum('fees'), 'realized' => $rows->sum('realized'), 'income' => $rows->sum('income'), 'result' => $rows->sum('result')];
    }
}

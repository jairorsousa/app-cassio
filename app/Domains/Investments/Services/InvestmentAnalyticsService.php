<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;

class InvestmentAnalyticsService
{
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

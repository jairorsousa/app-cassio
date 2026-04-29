<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Models\Writ;

class WritProfitabilityCalculator
{
    /**
     * @return array{
     *   profit_amount: float,
     *   profit_percentage: float,
     *   days_elapsed: ?int,
     *   monthly_rate: ?float,
     * }
     */
    public function realized(Writ $writ): array
    {
        $paid = (float) $writ->paid_amount;
        $received = (float) ($writ->actual_receipt_amount ?? 0);

        $profit = round($received - $paid, 2);
        $profitPct = $paid > 0 ? round(($profit / $paid) * 100, 3) : 0.0;

        $days = null;
        $monthlyRate = null;

        if ($writ->paid_at && $writ->finalized_at) {
            $days = $writ->paid_at->diffInDays($writ->finalized_at);

            if ($days > 0 && $paid > 0) {
                $months = $days / 30.0;
                $monthlyRate = round((pow(1 + $profit / $paid, 1 / max($months, 0.0001)) - 1) * 100, 3);
            }
        }

        return [
            'profit_amount' => $profit,
            'profit_percentage' => $profitPct,
            'days_elapsed' => $days,
            'monthly_rate' => $monthlyRate,
        ];
    }
}

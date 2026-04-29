<?php

namespace App\Domains\Partnership\Services;

use App\Domains\Partnership\Models\Partnership;
use Illuminate\Support\Carbon;

class PartnershipProfitabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Partnership $partnership, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $contribQ = $partnership->contributions()->where('status', 'done');
        $expQ = $partnership->expenses();
        $distQ = $partnership->distributions();

        if ($from) {
            $contribQ->where('date', '>=', $from);
            $expQ->where('date', '>=', $from);
            $distQ->where('date', '>=', $from);
        }
        if ($to) {
            $contribQ->where('date', '<=', $to);
            $expQ->where('date', '<=', $to);
            $distQ->where('date', '<=', $to);
        }

        $totalContrib = (float) $contribQ->sum('amount');
        $totalExp = (float) $expQ->sum('proportional_amount');
        $totalDist = (float) $distQ->sum('amount');
        $base = $totalContrib + $totalExp;
        $netResult = round($totalDist - $base, 2);
        $roi = $base > 0 ? round(($netResult / $base) * 100, 3) : 0.0;

        $now = $to ?? Carbon::now();
        $start = $from ?? $partnership->joined_at ?? Carbon::now()->subYear();
        $months = max(0.001, $start->floatDiffInMonths($now));
        $monthlyRoi = $base > 0 && $netResult > 0
            ? round((pow(1 + $netResult / $base, 1 / $months) - 1) * 100, 3)
            : 0.0;

        return [
            'total_contributed' => round($totalContrib, 2),
            'total_expenses' => round($totalExp, 2),
            'total_invested' => round($base, 2),
            'total_distributions' => round($totalDist, 2),
            'net_result' => $netResult,
            'roi_percent' => $roi,
            'monthly_roi_percent' => $monthlyRoi,
            'months_elapsed' => round($months, 1),
        ];
    }
}

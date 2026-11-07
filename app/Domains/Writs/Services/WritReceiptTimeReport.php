<?php

namespace App\Domains\Writs\Services;

use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Tempo real entre a compra (data do pagamento da cessão) e o recebimento, comparado com o prazo estimado. */
class WritReceiptTimeReport
{
    /** "TJMA / Vara Única…" → "TJMA"; "TRF!" → "TRF1". */
    public static function courtKey(?string $court): string
    {
        $k = mb_strtoupper(trim((string) strtok((string) $court, '/')));
        $k = str_replace('!', '1', preg_replace('/\s+/', '', $k) ?? '');

        return $k !== '' ? $k : 'Sem tribunal';
    }

    /** @return list<array{writ: Writ, days: int, months: float, estimated_months: ?int, diff_months: ?float}> */
    public function rows(): array
    {
        return Writ::where('stage', 'finalized')->whereNotNull('paid_at')->whereNotNull('finalized_at')
            ->orderByDesc('finalized_at')->get()
            ->map(function (Writ $w) {
                $days = (int) Carbon::parse($w->paid_at)->startOfDay()->diffInDays(Carbon::parse($w->finalized_at)->startOfDay());
                $months = round($days / 30.4375, 1);

                return ['writ' => $w, 'days' => $days, 'months' => $months, 'estimated_months' => $w->estimated_months ?: null,
                    'diff_months' => $w->estimated_months ? round($months - $w->estimated_months, 1) : null];
            })->values()->all();
    }

    /** @return array{by_type: list<array>, by_court: list<array>} */
    public function summary(): array
    {
        $rows = collect($this->rows());
        $agg = fn (Collection $g, string $label) => [
            'label' => $label,
            'count' => $g->count(),
            'avg_days' => (int) round($g->avg('days')),
            'avg_months' => round((float) $g->avg('months'), 1),
            'avg_estimated' => $g->whereNotNull('estimated_months')->isEmpty() ? null : round((float) $g->whereNotNull('estimated_months')->avg('estimated_months'), 1),
            'min_months' => round((float) $g->min('months'), 1),
            'max_months' => round((float) $g->max('months'), 1),
        ];

        return [
            'by_type' => $rows->groupBy(fn ($r) => $r['writ']->type)->map(fn ($g, $t) => $agg($g, $t === 'rpv' ? 'RPV' : 'Precatório'))->values()->all(),
            'by_court' => $rows->groupBy(fn ($r) => self::courtKey($r['writ']->court).' · '.($r['writ']->type === 'rpv' ? 'RPV' : 'Precatório'))
                ->map(fn ($g, $k) => $agg($g, $k))->sortKeys()->values()->all(),
        ];
    }
}

<?php

namespace App\Domains\Dashboard\Support;

use App\Support\BusinessDate;
use Illuminate\Support\Carbon;

/**
 * Período de relatório. Fluxos (receitas, despesas, resultado) usam [start, end].
 * Posições (saldos, faturas abertas, capital em risco) usam positionDate(): o último dia do período,
 * limitado a hoje (não existe posição futura).
 */
final class ReportPeriod
{
    public const PRESETS = [
        'this_month' => 'Este mês',
        'last_3' => 'Últimos 3 meses',
        'last_6' => 'Últimos 6 meses',
        'last_12' => 'Últimos 12 meses',
        'custom' => 'Intervalo livre',
    ];

    public const MAX_DAYS = 1830;

    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $preset,
        private readonly Carbon $today,
    ) {}

    public static function fromInput(?string $preset, ?string $start = null, ?string $end = null, ?string $today = null): self
    {
        $todayC = Carbon::parse($today ?? BusinessDate::today())->startOfDay();
        $preset = array_key_exists((string) $preset, self::PRESETS) ? (string) $preset : 'this_month';

        if ($preset === 'custom') {
            $s = self::parseDate($start);
            $e = self::parseDate($end);
            if (! $s || ! $e) {
                return self::fromInput('this_month', null, null, $todayC->toDateString());
            }
            if ($s->gt($e)) {
                [$s, $e] = [$e, $s];
            }
            if ($s->diffInDays($e) > self::MAX_DAYS) {
                $s = $e->copy()->subDays(self::MAX_DAYS);
            }

            return new self($s->startOfDay(), $e->endOfDay(), 'custom', $todayC);
        }

        $months = ['this_month' => 1, 'last_3' => 3, 'last_6' => 6, 'last_12' => 12][$preset];
        $s = $todayC->copy()->startOfMonth()->subMonthsNoOverflow($months - 1);

        return new self($s, $todayC->copy()->endOfMonth(), $preset, $todayC);
    }

    public static function month(string $ym, ?string $today = null): self
    {
        $s = Carbon::createFromFormat('Y-m-d', $ym.'-01')->startOfMonth();

        return self::fromInput('custom', $s->toDateString(), $s->copy()->endOfMonth()->toDateString(), $today);
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $d && $d->format('Y-m-d') === $value ? $d : null;
    }

    /** Data da posição: último dia do período, nunca depois de hoje. */
    public function positionDate(): Carbon
    {
        $end = $this->end->copy()->startOfDay();

        return $end->gt($this->today) ? $this->today->copy() : $end;
    }

    public function isPositionToday(): bool
    {
        return $this->positionDate()->isSameDay($this->today);
    }

    public function today(): Carbon
    {
        return $this->today->copy();
    }

    /** Mesmo número de meses (ou de dias, se o período não for de meses fechados) imediatamente antes. */
    public function previous(): self
    {
        if ($this->isWholeMonths()) {
            $months = $this->monthCount();
            $s = $this->start->copy()->subMonthsNoOverflow($months)->startOfMonth();
            $e = $this->start->copy()->subDay()->endOfDay();
        } else {
            $days = (int) $this->start->copy()->startOfDay()->diffInDays($this->end->copy()->startOfDay()) + 1;
            $e = $this->start->copy()->subDay()->endOfDay();
            $s = $e->copy()->subDays($days - 1)->startOfDay();
        }

        return new self($s, $e, 'custom', $this->today);
    }

    public function isWholeMonths(): bool
    {
        return $this->start->day === 1 && $this->end->copy()->startOfDay()->isSameDay($this->end->copy()->endOfMonth()->startOfDay());
    }

    public function monthCount(): int
    {
        return count($this->months());
    }

    /** @return list<array{key:string,label:string,start:Carbon,end:Carbon}> meses do período, recortados nas bordas */
    public function months(): array
    {
        $rows = [];
        $cursor = $this->start->copy()->startOfMonth();
        while ($cursor->lte($this->end)) {
            $s = $cursor->copy()->max($this->start);
            $e = $cursor->copy()->endOfMonth()->min($this->end);
            $rows[] = ['key' => $cursor->format('Y-m'), 'label' => self::monthLabel($cursor), 'start' => $s->copy(), 'end' => $e->copy()];
            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $rows;
    }

    public static function monthLabel(Carbon $date): string
    {
        $names = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return $names[$date->month - 1].'/'.$date->format('y');
    }

    public function label(): string
    {
        $range = $this->start->format('d/m/Y').' a '.$this->end->format('d/m/Y');

        return $this->preset === 'custom' ? $range : self::PRESETS[$this->preset].' ('.$range.')';
    }

    /** Variação com divisão por zero tratada: pct = null quando a base é zero. */
    public static function change(float $current, float $previous): array
    {
        $diff = round($current - $previous, 2);
        $pct = abs($previous) < 0.005 ? null : round($diff / abs($previous) * 100, 1);

        return ['current' => round($current, 2), 'previous' => round($previous, 2), 'diff' => $diff, 'pct' => $pct];
    }
}

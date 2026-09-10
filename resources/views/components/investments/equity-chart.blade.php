@props(['rows'])
@php
    $points = collect($rows);
    $hasData = $points->sum('market_value') + $points->sum('invested') > 0;
    $last = (float) ($points->last()['market_value'] ?? 0);
    $values = $points->flatMap(fn ($row) => [$row['market_value'], $row['invested']]);
    $min = (float) $values->min();
    $max = (float) $values->max();
    $padding = max(($max - $min) * 0.12, $max * 0.04, 1);
    $chartMin = $min <= 0 ? 0.0 : max(0, $min - $padding);
    $chartMax = max(1, $max + $padding);
    $range = max(0.01, $chartMax - $chartMin);
    $count = $points->count();
    $coordinates = $points->values()->map(function ($row, $index) use ($count, $chartMin, $range) {
        $x = $count <= 1 ? 50 : round($index / ($count - 1) * 100, 3);

        return [
            'x' => $x,
            'market_y' => round(100 - (((float) $row['market_value'] - $chartMin) / $range * 100), 3),
            'invested_y' => round(100 - (((float) $row['invested'] - $chartMin) / $range * 100), 3),
        ];
    });
    $marketLine = $coordinates->map(fn ($point) => $point['x'].','.$point['market_y'])->implode(' ');
    $investedLine = $coordinates->map(fn ($point) => $point['x'].','.$point['invested_y'])->implode(' ');
    $marketArea = '0,100 '.$marketLine.' 100,100';
@endphp
<div class="mb-5 flex flex-wrap items-center justify-between gap-4">
    <div class="flex flex-wrap gap-4 text-xs text-mono-600">
        <span><span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm bg-primary-500"></span>Patrimônio</span>
        <span><span class="mr-1.5 inline-block h-2.5 w-2.5 rounded-sm bg-info"></span>Valor aplicado</span>
    </div>
    <p class="text-sm text-mono-600">Atual: <strong class="text-mono-900">R$ {{ number_format($last, 2, ',', '.') }}</strong></p>
</div>
@if (! $hasData)
    <div class="flex h-64 items-center justify-center rounded-2xl bg-mono-50 text-sm text-mono-600">Registre compras para acompanhar a evolução do patrimônio.</div>
@else
    <div class="investment-line-chart" role="img" aria-label="Evolução do patrimônio nos últimos 12 meses">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-64 w-full overflow-visible" aria-hidden="true">
            <defs>
                <linearGradient id="investment-equity-fill" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="var(--colors-primary-g500)" stop-opacity="0.18" />
                    <stop offset="100%" stop-color="var(--colors-primary-g500)" stop-opacity="0.01" />
                </linearGradient>
            </defs>
            @foreach ([0, 25, 50, 75, 100] as $gridY)
                <line x1="0" x2="100" y1="{{ $gridY }}" y2="{{ $gridY }}" stroke="var(--colors-mono-g100)" stroke-width="1" vector-effect="non-scaling-stroke" />
            @endforeach
            <polygon points="{{ $marketArea }}" fill="url(#investment-equity-fill)" />
            <polyline points="{{ $investedLine }}" fill="none" stroke="var(--colors-info)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <polyline points="{{ $marketLine }}" fill="none" stroke="var(--colors-primary-g500)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        </svg>
        <div class="mt-3 flex justify-between text-[9px] text-mono-600 sm:text-[10px]">
            @foreach ($rows as $row)
                <span class="min-w-0 flex-1 text-center"><span class="sm:hidden">{{ explode('/', $row['label'])[0] }}</span><span class="hidden sm:inline">{{ $row['label'] }}</span></span>
            @endforeach
        </div>
    </div>
    <details class="mt-4 text-xs text-mono-600">
        <summary class="cursor-pointer">Ver valores mensais</summary>
        <div class="overflow-x-auto">
            <table class="investment-table">
                <thead>
                    <tr>
                        <th>Mês</th>
                        <th>Patrimônio</th>
                        <th>Capital investido</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td>R$ {{ number_format($row['market_value'], 2, ',', '.') }}</td>
                            <td>R$ {{ number_format($row['invested'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif

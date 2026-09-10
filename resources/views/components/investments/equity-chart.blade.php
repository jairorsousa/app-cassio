@props(['rows'])
@php
    $points = collect($rows);
    $hasData = $points->sum('market_value') + $points->sum('invested') > 0;
    $first = (float) ($points->first()['market_value'] ?? 0);
    $last = (float) ($points->last()['market_value'] ?? 0);
    $change = round($last - $first, 2);
    $changePct = $first > 0 ? round(($change / $first) * 100, 1) : null;
    $values = $points->flatMap(fn ($row) => [$row['market_value'], $row['invested']]);
    $min = (float) $values->min();
    $max = (float) $values->max();
    $pad = max(($max - $min) * 0.12, $max * 0.04, 1);
    $chartMin = $min <= 0 ? 0.0 : max(0, $min - $pad);
    $chartMax = $max + $pad;
    $range = max(0.01, $chartMax - $chartMin);
    $count = $points->count();
    $coords = $points->values()->map(function ($row, $index) use ($count, $chartMin, $range) {
        $x = $count <= 1 ? 50 : round($index / ($count - 1) * 100, 3);
        $marketY = round(100 - (($row['market_value'] - $chartMin) / $range * 100), 3);
        $investedY = round(100 - (($row['invested'] - $chartMin) / $range * 100), 3);

        return [
            'x' => $x,
            'market_y' => $marketY,
            'invested_y' => $investedY,
            'label' => $row['label'],
            'market_value' => $row['market_value'],
            'invested' => $row['invested'],
        ];
    });
    $marketLine = $coords->map(fn ($p) => $p['x'].','.$p['market_y'])->implode(' ');
    $investedLine = $coords->map(fn ($p) => $p['x'].','.$p['invested_y'])->implode(' ');
    $area = '0,100 '.$marketLine.' 100,100';
@endphp
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-xs font-medium text-mono-600">Patrimônio atual</p>
        <p class="mt-1 text-2xl font-bold">R$ {{ number_format($last, 2, ',', '.') }}</p>
    </div>
    <div class="text-right">
        <p class="text-xs font-medium text-mono-600">Variação no período</p>
        <p class="mt-1 text-sm font-semibold {{ $change >= 0 ? 'text-up' : 'text-down' }}">
            {{ $change >= 0 ? '+' : '' }}R$ {{ number_format($change, 2, ',', '.') }}
            @if ($changePct !== null)
                · {{ $change >= 0 ? '+' : '' }}{{ number_format($changePct, 1, ',', '.') }}%
            @endif
        </p>
    </div>
</div>
<div class="mb-4 flex flex-wrap gap-4 text-xs text-mono-600">
    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-primary-500"></span>Patrimônio</span>
    <span><span class="mr-1 inline-block h-2 w-4 border-t-2 border-dashed border-info align-middle"></span>Capital investido</span>
</div>
@if (! $hasData)
    <div class="flex h-52 items-center justify-center rounded-2xl bg-mono-50 text-sm text-mono-600">Registre compras para acompanhar a evolução do patrimônio.</div>
@else
    <div class="relative">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-52 w-full overflow-visible" role="img" aria-label="Evolução do patrimônio nos últimos 12 meses">
            <defs>
                <linearGradient id="equity-fill" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="#ff6f00" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="#ff6f00" stop-opacity="0.02" />
                </linearGradient>
            </defs>
            <polygon points="{{ $area }}" fill="url(#equity-fill)" />
            <polyline points="{{ $investedLine }}" fill="none" stroke="#1a73e8" stroke-width="0.7" stroke-dasharray="2 1.6" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <polyline points="{{ $marketLine }}" fill="none" stroke="#ff6f00" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            @foreach ($coords as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['market_y'] }}" r="1.15" fill="#ff6f00" class="max-sm:hidden">
                    <title>{{ $point['label'] }} · Patrimônio R$ {{ number_format($point['market_value'], 2, ',', '.') }} · Investido R$ {{ number_format($point['invested'], 2, ',', '.') }}</title>
                </circle>
            @endforeach
        </svg>
        <div class="mt-2 flex justify-between text-[9px] text-mono-600 sm:text-[10px]">
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

@props(['rows'])
@php
    $points = collect($rows);
    $hasData = $points->sum('market_value') + $points->sum('invested') > 0;
    $last = (float) ($points->last()['market_value'] ?? 0);
    $values = $points->flatMap(fn ($row) => [$row['market_value'], $row['invested']]);
    $max = (float) $values->max();
    $chartMax = max(1, $max * 1.12);
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
    <div class="investment-bar-chart" role="img" aria-label="Evolução do patrimônio nos últimos 12 meses">
        <div class="investment-chart-grid" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <div class="investment-chart-columns">
            @foreach ($rows as $row)
                @php
                    $marketHeight = max(1.5, ((float) $row['market_value'] / $chartMax) * 100);
                    $investedHeight = max(1.5, ((float) $row['invested'] / $chartMax) * 100);
                @endphp
                <div class="investment-chart-month">
                    <div class="investment-chart-bars">
                        <span class="investment-chart-bar is-invested" style="height: {{ $investedHeight }}%" title="{{ $row['label'] }} · Valor aplicado: R$ {{ number_format($row['invested'], 2, ',', '.') }}"></span>
                        <span class="investment-chart-bar is-market" style="height: {{ $marketHeight }}%" title="{{ $row['label'] }} · Patrimônio: R$ {{ number_format($row['market_value'], 2, ',', '.') }}"></span>
                    </div>
                    <span class="investment-chart-label"><span class="sm:hidden">{{ explode('/', $row['label'])[0] }}</span><span class="hidden sm:inline">{{ $row['label'] }}</span></span>
                </div>
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

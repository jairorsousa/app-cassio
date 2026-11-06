@props(['type' => 'bar', 'labels' => [], 'datasets' => [], 'title' => 'Gráfico', 'height' => 180])
@php
    // Gráfico SVG gerado no servidor (sem biblioteca externa). Sempre acompanhado de tabela de dados para leitores de tela.
    $palette = ['#22c55e', '#ef4444', '#60a5fa', '#f59e0b', '#a78bfa', '#14b8a6', '#f472b6', '#94a3b8', '#eab308', '#fb923c'];
    $labels = array_values($labels);
    $n = max(1, count($labels));
    $W = 600; $H = (int) $height; $padL = 56; $padR = 8; $padT = 10; $padB = 26;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $all = collect($datasets)->flatMap(fn ($d) => $d['values'])->map(fn ($v) => (float) $v);
    $max = max(0.0, (float) $all->max()); $min = min(0.0, (float) $all->min());
    if ($max - $min < 0.01) { $max = $min + 1; }
    $y = fn ($v) => $padT + ($max - (float) $v) / ($max - $min) * $plotH;
    $zeroY = $y(0);
    $fmt = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $short = function ($v) { $a = abs($v); return ($v < 0 ? '-' : '').($a >= 1e6 ? number_format($a / 1e6, 1, ',', '.').'mi' : ($a >= 1e3 ? number_format($a / 1e3, 0, ',', '.').'k' : number_format($a, 0, ',', '.'))); };
    $isEmpty = $all->filter(fn ($v) => abs($v) > 0.004)->isEmpty();
@endphp
<figure {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($isEmpty)
        <div class="text-fs-12 text-cryptex-text-tertiary py-space-4">Sem valores no período.</div>
    @elseif ($type === 'pie')
        @php
            $values = array_map(fn ($v) => max(0, (float) $v), $datasets[0]['values'] ?? []);
            $total = array_sum($values) ?: 1; $cx = 90; $cy = 90; $r = 80; $angle = -M_PI / 2;
        @endphp
        <div class="flex flex-col sm:flex-row gap-space-4 items-center">
            <svg viewBox="0 0 180 180" class="w-40 h-40 shrink-0" role="img" aria-label="{{ $title }} (pizza)">
                <title>{{ $title }}</title>
                @foreach ($values as $i => $v)
                    @php
                        $frac = $v / $total; $end = $angle + $frac * 2 * M_PI;
                        $x1 = $cx + $r * cos($angle); $y1 = $cy + $r * sin($angle); $x2 = $cx + $r * cos($end); $y2 = $cy + $r * sin($end);
                        $large = $frac > 0.5 ? 1 : 0; $color = $palette[$i % count($palette)];
                    @endphp
                    @if ($frac >= 0.9999)
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="{{ $color }}"><title>{{ $labels[$i] ?? '' }}: {{ $fmt($v) }}</title></circle>
                    @elseif ($frac > 0)
                        <path d="M{{ $cx }},{{ $cy }} L{{ round($x1, 2) }},{{ round($y1, 2) }} A{{ $r }},{{ $r }} 0 {{ $large }} 1 {{ round($x2, 2) }},{{ round($y2, 2) }} Z" fill="{{ $color }}"><title>{{ $labels[$i] ?? '' }}: {{ $fmt($v) }} ({{ number_format($frac * 100, 1, ',', '.') }}%)</title></path>
                    @endif
                    @php $angle = $end; @endphp
                @endforeach
            </svg>
            <ul class="flex flex-col gap-1 text-fs-12 w-full min-w-0" aria-hidden="true">
                @foreach ($values as $i => $v)
                    <li class="flex items-center gap-space-2 min-w-0">
                        <span class="inline-block w-3 h-3 rounded-sm shrink-0" style="background: {{ $palette[$i % count($palette)] }}"></span>
                        <span class="truncate text-cryptex-text-secondary">{{ $labels[$i] ?? '' }}</span>
                        <span class="ml-auto font-mono [font-variant-numeric:tabular-nums] text-cryptex-text-primary">{{ number_format($v / $total * 100, 1, ',', '.') }}%</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full h-auto" preserveAspectRatio="none" role="img" aria-label="{{ $title }} ({{ ['bar' => 'barras', 'line' => 'linha', 'area' => 'área'][$type] ?? $type }})">
            <title>{{ $title }}</title>
            @foreach ([0, 0.5, 1] as $g)
                @php $gv = $min + ($max - $min) * $g; $gy = $y($gv); @endphp
                <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ round($gy, 2) }}" y2="{{ round($gy, 2) }}" stroke="currentColor" class="text-cryptex-border-subtle" stroke-width="1" opacity="0.4" />
                <text x="{{ $padL - 6 }}" y="{{ round($gy + 4, 2) }}" text-anchor="end" font-size="11" fill="currentColor" class="text-cryptex-text-tertiary">{{ $short($gv) }}</text>
            @endforeach
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ round($zeroY, 2) }}" y2="{{ round($zeroY, 2) }}" stroke="currentColor" class="text-cryptex-text-tertiary" stroke-width="1" />
            @php $slot = $plotW / $n; $dsCount = max(1, count($datasets)); @endphp
            @foreach ($datasets as $di => $ds)
                @php $color = $ds['color'] ?? $palette[$di % count($palette)]; $vals = array_values($ds['values']); @endphp
                @if ($type === 'bar')
                    @php $bw = max(2, $slot * 0.7 / $dsCount); @endphp
                    @foreach ($vals as $i => $v)
                        @php $bx = $padL + $i * $slot + $slot * 0.15 + $di * $bw; $vy = $y($v); @endphp
                        <rect x="{{ round($bx, 2) }}" y="{{ round(min($vy, $zeroY), 2) }}" width="{{ round($bw, 2) }}" height="{{ round(max(1, abs($zeroY - $vy)), 2) }}" fill="{{ $color }}" rx="1"><title>{{ $ds['name'] }} · {{ $labels[$i] ?? '' }}: {{ $fmt($v) }}</title></rect>
                    @endforeach
                @else
                    @php
                        $pts = collect($vals)->map(fn ($v, $i) => round($padL + ($i + 0.5) * $slot, 2).','.round($y($v), 2))->implode(' ');
                        $firstX = round($padL + 0.5 * $slot, 2); $lastX = round($padL + (count($vals) - 0.5) * $slot, 2);
                    @endphp
                    @if ($type === 'area')
                        <polygon points="{{ $firstX }},{{ round($zeroY, 2) }} {{ $pts }} {{ $lastX }},{{ round($zeroY, 2) }}" fill="{{ $color }}" opacity="0.25" />
                    @endif
                    <polyline points="{{ $pts }}" fill="none" stroke="{{ $color }}" stroke-width="2.5" stroke-linejoin="round" stroke-dasharray="{{ ($ds['dashed'] ?? false) ? '6 4' : '' }}" />
                    @foreach ($vals as $i => $v)
                        <circle cx="{{ round($padL + ($i + 0.5) * $slot, 2) }}" cy="{{ round($y($v), 2) }}" r="3" fill="{{ $color }}"><title>{{ $ds['name'] }} · {{ $labels[$i] ?? '' }}: {{ $fmt($v) }}</title></circle>
                    @endforeach
                @endif
            @endforeach
            @php $step = (int) ceil($n / 12); @endphp
            @foreach ($labels as $i => $label)
                @if ($i % $step === 0)
                    <text x="{{ round($padL + ($i + 0.5) * $slot, 2) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="11" fill="currentColor" class="text-cryptex-text-tertiary">{{ $label }}</text>
                @endif
            @endforeach
        </svg>
        @if (count($datasets) > 1)
            <div class="flex flex-wrap gap-space-3 text-fs-12 mt-space-1" aria-hidden="true">
                @foreach ($datasets as $di => $ds)
                    <span class="flex items-center gap-1 text-cryptex-text-secondary"><span class="inline-block w-3 h-3 rounded-sm" style="background: {{ $ds['color'] ?? $palette[$di % count($palette)] }}"></span>{{ $ds['name'] }}</span>
                @endforeach
            </div>
        @endif
    @endif
    <table class="sr-only">
        <caption>{{ $title }}</caption>
        <thead><tr><th scope="col">Item</th>@foreach ($datasets as $ds)<th scope="col">{{ $ds['name'] ?? 'Valor' }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($labels as $i => $label)
                <tr><th scope="row">{{ $label }}</th>@foreach ($datasets as $ds)<td>{{ $fmt($ds['values'][$i] ?? 0) }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
</figure>

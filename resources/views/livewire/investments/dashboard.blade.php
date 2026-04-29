<?php

use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Domains\Investments\Services\RealizedPnLCalculator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-md animate-pulse">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
                <div class="h-24 bg-mono-100 rounded-md"></div>
                <div class="h-24 bg-mono-100 rounded-md"></div>
                <div class="h-24 bg-mono-100 rounded-md"></div>
                <div class="h-24 bg-mono-100 rounded-md"></div>
            </div>
            <div class="h-40 bg-mono-100 rounded-md"></div>
        </div>
        HTML;
    }

    public function with(PortfolioProfitabilityService $portfolio, RealizedPnLCalculator $pnl): array
    {
        $summary = $portfolio->summary();

        $monthDividends = (float) AssetDividend::whereBetween('payment_date', [
            Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
        ])->sum('total');

        $realized12m = $pnl->forPeriod(Carbon::now()->subYear(), Carbon::now());

        return [
            'summary' => $summary,
            'monthDividends' => $monthDividends,
            'realized12m' => $realized12m['total'],
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Dashboard</x-slot>

<div class="flex flex-col gap-md">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Valor de mercado</div>
            <div class="text-xl font-bold">R$ {{ number_format($summary['market_value'], 2, ',', '.') }}</div>
            <div class="text-xxs text-mono-600 mt-xxxs">Investido: R$ {{ number_format($summary['total_invested'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">PnL não realizado</div>
            <div class="text-xl font-bold {{ $summary['unrealized_pnl'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($summary['unrealized_pnl'], 2, ',', '.') }}
            </div>
            <div class="text-xxs text-mono-600 mt-xxxs">{{ number_format($summary['unrealized_pct'], 2, ',', '.') }}%</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Proventos (12m)</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($summary['dividends_12m'], 2, ',', '.') }}</div>
            <div class="text-xxs text-mono-600 mt-xxxs">YoC: {{ number_format($summary['yield_on_cost_12m'], 2, ',', '.') }}%</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">PnL realizado total</div>
            <div class="text-xl font-bold {{ $summary['realized_pnl_total'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($summary['realized_pnl_total'], 2, ',', '.') }}
            </div>
            <div class="text-xxs text-mono-600 mt-xxxs">Mês corrente em proventos: R$ {{ number_format($monthDividends, 2, ',', '.') }}</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Distribuição por classe</h3>
        @php $totalMv = collect($summary['by_class'])->sum('market_value'); @endphp
        @if ($totalMv <= 0)
            <div class="text-sm text-mono-600">Sem posições ativas.</div>
        @else
            <ul class="flex flex-col gap-xs">
                @foreach ($summary['by_class'] as $class => $row)
                    @php $pct = $totalMv > 0 ? ($row['market_value'] / $totalMv) * 100 : 0; @endphp
                    <li>
                        <div class="flex justify-between text-xs mb-xxxs">
                            <span>{{ $class }}</span>
                            <span>R$ {{ number_format($row['market_value'], 2, ',', '.') }} ({{ number_format($pct, 1, ',', '.') }}%)</span>
                        </div>
                        <div class="h-2 bg-mono-100 rounded-md overflow-hidden">
                            <div class="h-full bg-primary-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-fx.card>

    <div class="flex gap-xs">
        <x-fx.button href="{{ route('investments.assets.index') }}" variant="standard">Ativos</x-fx.button>
        <x-fx.button href="{{ route('investments.operations.index') }}" variant="standard">Operações</x-fx.button>
        <x-fx.button href="{{ route('investments.dividends.index') }}" variant="standard">Proventos</x-fx.button>
        <x-fx.button href="{{ route('investments.positions') }}" variant="primary">Posições</x-fx.button>
        <x-fx.button href="{{ route('investments.reports') }}" variant="standard">Rentabilidade</x-fx.button>
    </div>
</div>

<?php

use App\Domains\Investments\Services\PortfolioProfitabilityService;
use App\Domains\Investments\Services\RealizedPnLCalculator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->subYear()->format('Y-m-d');
            $this->to = now()->format('Y-m-d');
        }
    }

    public function with(PortfolioProfitabilityService $portfolio, RealizedPnLCalculator $pnl): array
    {
        $summary = $portfolio->summary();
        $realized = $pnl->forPeriod(Carbon::parse($this->from), Carbon::parse($this->to));

        return compact('summary', 'realized');
    }
}; ?>

<x-slot name="header">Investimentos · Rentabilidade</x-slot>

<div class="flex flex-col gap-md">
    <x-fx.card>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-xs items-end">
            <x-fx.input label="De" type="date" wire:model.live="from" />
            <x-fx.input label="Até" type="date" wire:model.live="to" />
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Total investido</div>
            <div class="text-xl font-bold">R$ {{ number_format($summary['total_invested'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Valor de mercado</div>
            <div class="text-xl font-bold">R$ {{ number_format($summary['market_value'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Retorno total</div>
            <div class="text-xl font-bold {{ $summary['total_return'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($summary['total_return'], 2, ',', '.') }}
            </div>
            <div class="text-xxs text-mono-600 mt-xxxs">PnL não real. + proventos 12m + PnL real. total</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Yield on Cost (12m)</div>
            <div class="text-xl font-bold">{{ number_format($summary['yield_on_cost_12m'], 2, ',', '.') }}%</div>
            <div class="text-xxs text-mono-600 mt-xxxs">Proventos 12m: R$ {{ number_format($summary['dividends_12m'], 2, ',', '.') }}</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Por classe</h3>
        @php $totalMv = collect($summary['by_class'])->sum('market_value'); @endphp
        <table class="fx-table w-full text-sm">
            <thead>
                <tr>
                    <th class="text-left">Classe</th>
                    <th class="text-right">Investido</th>
                    <th class="text-right">Valor mercado</th>
                    <th class="text-right">PnL</th>
                    <th class="text-right">% do total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['by_class'] as $name => $row)
                    @php
                        $pnl = $row['market_value'] - $row['invested'];
                        $share = $totalMv > 0 ? ($row['market_value'] / $totalMv) * 100 : 0;
                    @endphp
                    <tr>
                        <td>{{ $name }}</td>
                        <td class="text-right">R$ {{ number_format($row['invested'], 2, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format($row['market_value'], 2, ',', '.') }}</td>
                        <td class="text-right {{ $pnl >= 0 ? 'text-system-up' : 'text-system-down' }}">
                            R$ {{ number_format($pnl, 2, ',', '.') }}
                        </td>
                        <td class="text-right">{{ number_format($share, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">PnL realizado no período</h3>
        <div class="text-xl font-bold {{ $realized['total'] >= 0 ? 'text-system-up' : 'text-system-down' }} mb-sm">
            R$ {{ number_format($realized['total'], 2, ',', '.') }}
        </div>
        @if ($realized['by_asset']->isNotEmpty())
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Ativo</th><th class="text-right">PnL realizado</th></tr></thead>
                <tbody>
                    @foreach ($realized['by_asset'] as $ticker => $value)
                        <tr>
                            <td class="font-semibold">{{ $ticker }}</td>
                            <td class="text-right {{ $value >= 0 ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($value, 2, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="text-sm text-mono-600">Nenhuma venda no período.</div>
        @endif
    </x-fx.card>
</div>

<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\InvestmentAnalyticsService;
use App\Domains\Investments\Services\PortfolioProfitabilityService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public function with(): array
    {
        return [
            'summary' => app(PortfolioProfitabilityService::class)->summary(),
            'cashflow' => app(InvestmentAnalyticsService::class)->monthlyCashflow(),
            'positions' => AssetPosition::with('asset.assetClass')->where('quantity', '>', 0)->get()->sortByDesc(fn ($p) => $p->marketValue()),
            'recent' => AssetOperation::with('asset')->orderByDesc('date')->orderByDesc('id')->limit(5)->get(),
            'assetCount' => Asset::count(),
            'withoutQuote' => Asset::whereHas('position', fn ($q) => $q->where('quantity', '>', 0))->whereDoesntHave('quotes')->count(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>

<div class="investment-area">
    <x-investments.subnav />
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="mb-1 text-xs font-semibold uppercase tracking-widest text-primary-500">Sua vida financeira, em perspectiva</p><h2 class="text-2xl font-bold tracking-tight">Visão geral dos investimentos</h2><p class="mt-2 text-sm text-mono-600">Acompanhe a carteira, os resultados e o dinheiro que seus ativos geram.</p></div>
        <x-jr.button href="{{ route('investments.operations.index') }}"><span class="material-icons-outlined text-[18px]">swap_horiz</span>Registrar movimentação</x-jr.button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-investments.metric label="Patrimônio em carteira" :value="'R$ '.number_format($summary['market_value'], 2, ',', '.')" :hint="$positions->count().' ativos em carteira · valores em reais'" icon="account_balance_wallet" />
        <x-investments.metric label="Capital em posição" :value="'R$ '.number_format($summary['total_invested'], 2, ',', '.')" hint="Custo das posições ainda em carteira, incluindo taxas" icon="savings" />
        <x-investments.metric label="Valorização em aberto" :value="'R$ '.number_format($summary['unrealized_pnl'], 2, ',', '.')" :hint="number_format($summary['unrealized_pct'], 2, ',', '.').'% sobre o custo das posições abertas'" :positive="$summary['unrealized_pnl'] >= 0" icon="trending_up" />
        <x-investments.metric label="Proventos em 12 meses" :value="'R$ '.number_format($summary['dividends_12m'], 2, ',', '.')" hint="Dividendos, JCP e rendimentos recebidos" icon="payments" :positive="true" />
    </div>

    @if ($assetCount === 0)
        <x-jr.card>
            <div class="flex flex-wrap items-center justify-between gap-6">
                <div class="max-w-xl"><span class="material-icons-outlined mb-3 rounded-2xl bg-primary-100 p-3 text-primary-500">rocket_launch</span><h3 class="text-lg font-bold">Comece a construir sua carteira</h3><p class="mt-2 text-sm text-mono-600">Cadastre o primeiro ativo, registre uma compra ou aplicação e informe uma cotação. Os indicadores serão calculados a partir desses registros.</p></div>
                <x-jr.button href="{{ route('investments.assets.index') }}">Cadastrar primeiro ativo<span class="material-icons-outlined text-[18px]">arrow_forward</span></x-jr.button>
            </div>
        </x-jr.card>
    @elseif ($withoutQuote > 0)
        <x-jr.alert variant="info">{{ $withoutQuote }} ativo(s) em carteira ainda usam o preço médio como referência de valor. Atualize as cotações na aba Carteira.</x-jr.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-jr.card class="xl:col-span-2">
            <div class="mb-6 flex items-center justify-between"><div><h3 class="text-base font-bold">Fluxo dos investimentos</h3><p class="mt-1 text-xs text-mono-600">Últimos 12 meses · movimentações em reais, não rentabilidade</p></div><span class="material-icons-outlined text-mono-300">bar_chart</span></div>
            <x-investments.cashflow-chart :rows="$cashflow" />
        </x-jr.card>
        <x-jr.card>
            <h3 class="text-base font-bold">Distribuição da carteira</h3><p class="mt-1 text-xs text-mono-600">Participação por classe no valor atual</p>
            @php
                $colors = ['#ff6f00', '#1a73e8', '#15a96f', '#8b5cf6', '#eab308', '#ec4899', '#06b6d4'];
                $segments = []; $offset = 0;
                foreach ($summary['by_class'] as $row) {
                    $share = $summary['market_value'] > 0 ? $row['market_value'] / $summary['market_value'] * 100 : 0;
                    $segments[] = $colors[count($segments) % count($colors)].' '.$offset.'% '.($offset + $share).'%';
                    $offset += $share;
                }
            @endphp
            <div class="mx-auto my-7 flex h-44 w-44 items-center justify-center rounded-full" style="background: {{ $segments ? 'conic-gradient('.implode(', ', $segments).')' : 'var(--colors-mono-g100)' }}">
                <div class="flex h-32 w-32 flex-col items-center justify-center rounded-full bg-mono-white"><span class="text-3xl font-bold">{{ $positions->count() }}</span><span class="text-xs text-mono-600">ativos na carteira</span></div>
            </div>
            <div class="space-y-3">
                @forelse ($summary['by_class'] as $label => $row)
                    <div class="flex justify-between gap-3 text-sm"><span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $colors[$loop->index % count($colors)] }}"></span>{{ $label }}</span><span class="font-semibold">{{ number_format($summary['market_value'] > 0 ? $row['market_value'] / $summary['market_value'] * 100 : 0, 1, ',', '.') }}%</span></div>
                @empty
                    <p class="text-center text-sm text-mono-600">Suas posições aparecerão aqui.</p>
                @endforelse
            </div>
        </x-jr.card>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-jr.card>
            <div class="mb-5 flex justify-between"><h3 class="font-bold">Maiores posições</h3><a class="text-sm font-semibold text-primary-500" href="{{ route('investments.positions') }}">Ver carteira</a></div>
            @forelse ($positions->take(5) as $position)
                <div class="flex items-center justify-between gap-4 border-b border-mono-100 py-4">
                    <div class="flex items-center gap-3"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-100 text-sm font-bold text-primary-500">{{ mb_substr($position->asset?->ticker ?? '', 0, 2) }}</span><div><p class="font-semibold">{{ $position->asset?->ticker }}</p><p class="text-xs text-mono-600">{{ $position->asset?->name }}</p></div></div>
                    <div class="text-right"><p class="font-semibold">R$ {{ number_format($position->marketValue(), 2, ',', '.') }}</p><p class="text-xs {{ $position->unrealizedPnL() >= 0 ? 'text-up' : 'text-down' }}">{{ number_format($position->unrealizedPnLPercent(), 2, ',', '.') }}% em aberto</p></div>
                </div>
            @empty
                <p class="py-8 text-sm text-mono-600">Registre uma compra para acompanhar suas posições.</p>
            @endforelse
        </x-jr.card>
        <x-jr.card>
            <div class="mb-5 flex justify-between"><h3 class="font-bold">Últimas movimentações</h3><a class="text-sm font-semibold text-primary-500" href="{{ route('investments.operations.index') }}">Ver todas</a></div>
            @forelse ($recent as $operation)
                <div class="flex items-center justify-between gap-4 border-b border-mono-100 py-4"><div class="flex items-center gap-3"><span class="material-icons-outlined rounded-xl bg-mono-50 p-2 text-mono-600">{{ $operation->type === 'buy' ? 'south_west' : 'north_east' }}</span><div><p class="font-semibold">{{ $operation->asset?->ticker }} · {{ $operation->type === 'buy' ? 'Compra' : 'Venda' }}</p><p class="text-xs text-mono-600">{{ $operation->date->format('d/m/Y') }}</p></div></div><p class="font-semibold">R$ {{ number_format($operation->total, 2, ',', '.') }}</p></div>
            @empty
                <p class="py-8 text-sm text-mono-600">Seu histórico de compras e vendas aparecerá aqui.</p>
            @endforelse
        </x-jr.card>
    </div>
    <p class="text-xs text-mono-600">Cotações informadas manualmente. Valores sem cotação usam o preço médio. O resultado não inclui impostos não registrados.</p>
</div>

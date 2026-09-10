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
            'evolution' => app(InvestmentAnalyticsService::class)->portfolioEvolution(),
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
        <x-jr.card class="investment-summary-card">
            <div class="investment-summary-title">
                <span class="investment-summary-icon material-icons-outlined">account_balance_wallet</span>
                <span>Patrimônio total</span>
            </div>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <p class="investment-summary-value">R$ {{ number_format($summary['market_value'], 2, ',', '.') }}</p>
                <span @class(['investment-rate-pill', 'is-positive' => $summary['unrealized_pct'] >= 0, 'is-negative' => $summary['unrealized_pct'] < 0])>
                    {{ number_format(abs($summary['unrealized_pct']), 2, ',', '.') }}%
                    <span class="material-icons-outlined">{{ $summary['unrealized_pct'] >= 0 ? 'north_east' : 'south_east' }}</span>
                </span>
            </div>
            <div class="investment-summary-detail">
                <span>Valor investido</span>
                <strong>R$ {{ number_format($summary['total_invested'], 2, ',', '.') }}</strong>
            </div>
        </x-jr.card>

        <x-jr.card class="investment-summary-card">
            <div class="investment-summary-title">
                <span class="investment-summary-icon material-icons-outlined">paid</span>
                <span>Lucro total</span>
            </div>
            <p @class(['investment-summary-value mt-5', 'text-up' => $summary['total_return'] >= 0, 'text-down' => $summary['total_return'] < 0])>
                {{ $summary['total_return'] < 0 ? '- ' : '' }}R$ {{ number_format(abs($summary['total_return']), 2, ',', '.') }}
            </p>
            <div class="mt-5 grid grid-cols-2 gap-4">
                <div class="investment-summary-detail mt-0">
                    <span>Ganho de capital</span>
                    <strong>R$ {{ number_format($summary['unrealized_pnl'] + $summary['realized_pnl_total'], 2, ',', '.') }}</strong>
                </div>
                <div class="investment-summary-detail mt-0">
                    <span>Proventos</span>
                    <strong>R$ {{ number_format($summary['dividends_total'], 2, ',', '.') }}</strong>
                </div>
            </div>
        </x-jr.card>

        <x-jr.card class="investment-summary-card">
            <div class="investment-summary-title">
                <span class="investment-summary-icon material-icons-outlined">payments</span>
                <span>Proventos recebidos (12M)</span>
            </div>
            <p class="investment-summary-value mt-5">R$ {{ number_format($summary['dividends_12m'], 2, ',', '.') }}</p>
            <div class="investment-summary-detail">
                <span>Total recebido</span>
                <strong>R$ {{ number_format($summary['dividends_total'], 2, ',', '.') }}</strong>
            </div>
        </x-jr.card>

        <x-jr.card class="investment-summary-card">
            <div class="investment-summary-title">
                <span class="investment-summary-icon material-icons-outlined">query_stats</span>
                <span>Rentabilidade</span>
            </div>
            <div class="mt-5 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs font-medium text-mono-600">Em aberto</p>
                    <span @class(['investment-rate-pill mt-2', 'is-positive' => $summary['unrealized_pct'] >= 0, 'is-negative' => $summary['unrealized_pct'] < 0])>
                        {{ number_format(abs($summary['unrealized_pct']), 2, ',', '.') }}%
                        <span class="material-icons-outlined">{{ $summary['unrealized_pct'] >= 0 ? 'north_east' : 'south_east' }}</span>
                    </span>
                </div>
                <div>
                    <p class="text-xs font-medium text-mono-600">Retorno total</p>
                    <span @class(['investment-rate-pill mt-2', 'is-positive' => $summary['total_return_pct'] >= 0, 'is-negative' => $summary['total_return_pct'] < 0])>
                        {{ number_format(abs($summary['total_return_pct']), 2, ',', '.') }}%
                        <span class="material-icons-outlined">{{ $summary['total_return_pct'] >= 0 ? 'north_east' : 'south_east' }}</span>
                    </span>
                </div>
            </div>
            <p class="mt-5 text-xs text-mono-600">Resultado sobre o capital atualmente investido</p>
        </x-jr.card>
    </div>

    @if ($assetCount === 0)
        <x-jr.card>
            <div class="flex flex-wrap items-center justify-between gap-6">
                <div class="max-w-xl"><span class="material-icons-outlined mb-3 rounded-2xl bg-primary-100 p-3 text-primary-500">rocket_launch</span><h3 class="text-lg font-bold">Comece a construir sua carteira</h3><p class="mt-2 text-sm text-mono-600">Cadastre o primeiro ativo e registre uma compra ou aplicação. Tickers da B3 recebem cotação automática; os demais podem ser informados na Carteira.</p></div>
                <x-jr.button href="{{ route('investments.assets.index') }}">Cadastrar primeiro ativo<span class="material-icons-outlined text-[18px]">arrow_forward</span></x-jr.button>
            </div>
        </x-jr.card>
    @elseif ($withoutQuote > 0)
        <x-jr.alert variant="info">{{ $withoutQuote }} ativo(s) em carteira ainda usam o preço médio como referência de valor. Na aba Carteira, use Atualizar cotações para ativos da B3 ou informe o preço manualmente.</x-jr.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <x-jr.card class="xl:col-span-3">
            <div class="investment-panel-header">
                <div><h3 class="text-lg font-bold">Evolução do patrimônio</h3><p class="mt-1 text-xs text-mono-600">Valor da carteira e capital investido mês a mês</p></div>
                <span class="investment-filter-pill"><span class="material-icons-outlined">calendar_month</span>12 meses</span>
            </div>
            <x-investments.equity-chart :rows="$evolution" />
        </x-jr.card>
        <x-jr.card class="xl:col-span-2">
            <div class="investment-panel-header">
                <div><h3 class="text-lg font-bold">Ativos na carteira</h3><p class="mt-1 text-xs text-mono-600">Participação por classe no valor atual</p></div>
                <span class="investment-filter-pill"><span class="material-icons-outlined">category</span>Todos os tipos</span>
            </div>
            @php
                $colors = ['#ff6f00', '#1a73e8', '#15a96f', '#8b5cf6', '#eab308', '#ec4899', '#06b6d4'];
                $segments = []; $offset = 0;
                foreach ($summary['by_class'] as $row) {
                    $share = $summary['market_value'] > 0 ? $row['market_value'] / $summary['market_value'] * 100 : 0;
                    $segments[] = $colors[count($segments) % count($colors)].' '.$offset.'% '.($offset + $share).'%';
                    $offset += $share;
                }
            @endphp
            <div class="mx-auto my-8 flex h-52 w-52 items-center justify-center rounded-full" style="background: {{ $segments ? 'conic-gradient('.implode(', ', $segments).')' : 'var(--colors-mono-g100)' }}">
                <div class="flex h-36 w-36 flex-col items-center justify-center rounded-full bg-mono-white"><span class="text-3xl font-bold">{{ $positions->count() }}</span><span class="text-xs text-mono-600">ativos na carteira</span></div>
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

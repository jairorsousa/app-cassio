<?php

use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-space-5 animate-pulse">
            <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
            </div>
            <div class="h-[192px] bg-cryptex-bg-tertiary rounded-md"></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-5">
                <div class="h-[160px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[160px] bg-cryptex-bg-tertiary rounded-md"></div>
            </div>
        </div>
        HTML;
    }

    public function refresh(DashboardSnapshotService $service): void
    {
        $service->refresh();
        session()->flash('status', 'Dashboard atualizado.');
    }

    public function with(DashboardSnapshotService $service): array
    {
        $snapshot = $service->current();

        return [
            'snapshot' => $snapshot,
            'generatedAt' => $snapshot['generated_at'] ?? null,
        ];
    }
}; ?>

<x-slot name="header">Dashboard Consolidado</x-slot>

<div class="flex flex-col gap-space-5">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <div class="flex justify-between items-center">
        <div class="text-fs-12 text-cryptex-text-tertiary font-mono">
            Última atualização:
            {{ $generatedAt ? \Illuminate\Support\Carbon::parse($generatedAt)->format('d/m/Y H:i') : '—' }}
        </div>
        <button class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-semibold text-fs-12 transition-colors duration-150" wire:click="refresh">↻ Atualizar agora</button>
    </div>

    {{-- Patrimônio total --}}
    <x-fx.card>
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-space-5">
            <div>
                <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Patrimônio total consolidado</div>
                <div class="text-fs-32 font-bold text-cryptex-text-primary font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['total'], 2, ',', '.') }}</div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-space-3 text-fs-12 text-cryptex-text-secondary md:flex-1 md:max-w-2xl">
                <div>
                    <div class="uppercase tracking-[0.05em] text-[10px]">Caixa</div>
                    <div class="font-medium text-cryptex-text-primary text-fs-14 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['cash_balance'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase tracking-[0.05em] text-[10px]">Carteira</div>
                    <div class="font-medium text-cryptex-text-primary text-fs-14 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['portfolio_market_value'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase tracking-[0.05em] text-[10px]">Sociedade</div>
                    <div class="font-medium text-cryptex-text-primary text-fs-14 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['partnership_exposed'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase tracking-[0.05em] text-[10px]">Requisitórios</div>
                    <div class="font-medium text-cryptex-text-primary text-fs-14 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['writs_capital_at_risk'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase tracking-[0.05em] text-[10px] text-cryptex-text-tertiary">(−) Faturas</div>
                    <div class="font-medium text-cryptex-red-500 text-fs-14 font-mono [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['patrimony']['open_invoices_total'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </x-fx.card>

    {{-- Resultado mensal + comparativos --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Resultado do mês</div>
            <div class="text-fs-24 font-bold font-mono [font-variant-numeric:tabular-nums] {{ $snapshot['month']['result'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                R$ {{ number_format($snapshot['month']['result'], 2, ',', '.') }}
            </div>
            <div class="text-fs-12 text-cryptex-text-secondary mt-space-1 font-mono [font-variant-numeric:tabular-nums]">
                Rec: {{ number_format($snapshot['month']['income'], 2, ',', '.') }}
                · Desp: {{ number_format($snapshot['month']['expense'], 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">Médias móveis (resultado)</div>
            <div class="grid grid-cols-3 gap-space-2 mt-space-2 text-fs-14">
                <div>
                    <div class="text-fs-12 text-cryptex-text-tertiary font-mono">3m</div>
                    <div class="font-medium font-mono [font-variant-numeric:tabular-nums] {{ $snapshot['averages']['last_3_months'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                        R$ {{ number_format($snapshot['averages']['last_3_months'], 0, ',', '.') }}
                    </div>
                </div>
                <div>
                    <div class="text-fs-12 text-cryptex-text-tertiary font-mono">6m</div>
                    <div class="font-medium font-mono [font-variant-numeric:tabular-nums] {{ $snapshot['averages']['last_6_months'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                        R$ {{ number_format($snapshot['averages']['last_6_months'], 0, ',', '.') }}
                    </div>
                </div>
                <div>
                    <div class="text-fs-12 text-cryptex-text-tertiary font-mono">12m</div>
                    <div class="font-medium font-mono [font-variant-numeric:tabular-nums] {{ $snapshot['averages']['last_12_months'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                        R$ {{ number_format($snapshot['averages']['last_12_months'], 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium">A pagar / a receber</div>
            <div class="text-fs-14 mt-space-2 flex flex-col gap-space-1 font-mono">
                <div class="flex justify-between items-center">
                    <span class="text-cryptex-text-secondary text-fs-12 font-sans">Pendente a pagar</span>
                    <span class="font-medium text-cryptex-red-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['pending']['payable'], 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-cryptex-text-secondary text-fs-12 font-sans">Pendente a receber</span>
                    <span class="font-medium text-cryptex-green-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['pending']['receivable'], 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between items-center mt-space-1 pt-space-1 border-t border-cryptex-border-subtle">
                    <span class="text-cryptex-text-secondary text-fs-12 font-sans">Adiant. corretores (aberto)</span>
                    <span class="font-medium text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['brokers']['advances_outstanding'] ?? 0, 2, ',', '.') }}</span>
                </div>
            </div>
        </x-fx.card>
    </div>

    {{-- Distribuição --}}
    <x-fx.card>
        <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">Distribuição do patrimônio</h3>
        @php $totalDist = collect($snapshot['distribution'])->sum('value'); @endphp
        @if ($totalDist <= 0)
            <div class="text-fs-14 text-cryptex-text-secondary">Sem dados para distribuir.</div>
        @else
            <ul class="flex flex-col gap-space-3">
                @foreach ($snapshot['distribution'] as $slice)
                    @php $pct = $totalDist > 0 ? ($slice['value'] / $totalDist) * 100 : 0; @endphp
                    <li>
                        <div class="flex justify-between text-fs-12 mb-space-1">
                            <span class="text-cryptex-text-primary">{{ $slice['label'] }}</span>
                            <span class="font-mono text-cryptex-text-secondary [font-variant-numeric:tabular-nums]">R$ {{ number_format($slice['value'], 2, ',', '.') }} <span class="text-cryptex-brand-400">({{ number_format($pct, 1, ',', '.') }}%)</span></span>
                        </div>
                        <div class="h-2 bg-cryptex-bg-tertiary rounded-full overflow-hidden">
                            <div class="h-full bg-cryptex-brand-400" style="width: {{ $pct }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-fx.card>

    {{-- Indicadores operacionais por módulo --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-5">
        {{-- Investimentos --}}
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary flex items-center gap-space-2"><span class="text-fs-18">📈</span> Investimentos</h3>
            <div class="grid grid-cols-3 gap-space-2 text-fs-14 mb-space-4">
                <div>
                    <div class="text-fs-12 text-cryptex-text-secondary">Investido</div>
                    <div class="font-medium font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['investments']['invested'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-fs-12 text-cryptex-text-secondary">Mercado</div>
                    <div class="font-medium font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['investments']['market_value'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-fs-12 text-cryptex-text-secondary">Proventos 12m</div>
                    <div class="font-medium font-mono text-cryptex-green-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['investments']['dividends_12m'], 2, ',', '.') }}</div>
                </div>
            </div>
            @if (!empty($snapshot['investments']['by_class']))
                <div class="text-[10px] text-cryptex-text-tertiary uppercase tracking-[0.05em] mb-space-2 font-medium">Por classe</div>
                <ul class="flex flex-col gap-space-1 text-fs-12">
                    @foreach ($snapshot['investments']['by_class'] as $class => $row)
                        <li class="flex justify-between py-1 border-b border-cryptex-border-subtle last:border-0">
                            <span class="text-cryptex-text-secondary">{{ $class }}</span>
                            <span class="font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($row['market_value'], 2, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        {{-- Requisitórios --}}
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary flex items-center gap-space-2"><span class="text-fs-18">⚖️</span> Requisitórios</h3>
            <div class="grid grid-cols-2 gap-space-2 text-fs-14 mb-space-4">
                <div>
                    <div class="text-fs-12 text-cryptex-text-secondary">Capital em risco</div>
                    <div class="font-medium font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['writs']['capital_at_risk'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-fs-12 text-cryptex-text-secondary">Lucro líquido exp.</div>
                    <div class="font-medium font-mono text-cryptex-green-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($snapshot['writs']['expected_net_profit'], 2, ',', '.') }}</div>
                </div>
            </div>
            <div class="text-[10px] text-cryptex-text-tertiary uppercase tracking-[0.05em] mb-space-2 font-medium">Por etapa</div>
            <ul class="flex flex-col gap-space-1 text-fs-12">
                @foreach ($snapshot['writs']['by_stage'] as $row)
                    <li class="flex justify-between items-center py-1 border-b border-cryptex-border-subtle last:border-0">
                        <span class="text-cryptex-text-secondary">{{ $row['label'] }}</span>
                        <span class="font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]"><span class="text-cryptex-brand-400 mr-2">{{ $row['count'] }}x</span> R$ {{ number_format($row['face_total'], 0, ',', '.') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-fx.card>

        {{-- Sociedade --}}
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary flex items-center gap-space-2"><span class="text-fs-18">🏢</span> Sociedade</h3>
            @if (empty($snapshot['partnerships']['list']))
                <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma sociedade ativa.</div>
            @else
                <ul class="flex flex-col gap-space-2 text-fs-14">
                    @foreach ($snapshot['partnerships']['list'] as $row)
                        <li class="flex justify-between py-space-1 border-b border-cryptex-border-subtle last:border-0">
                            <span class="text-cryptex-text-primary">{{ $row['name'] }}</span>
                            <span class="font-mono [font-variant-numeric:tabular-nums]">
                                <span class="text-[10px] text-cryptex-text-tertiary uppercase">exp.</span>
                                <span class="text-cryptex-text-secondary">R$ {{ number_format($row['exposed'], 2, ',', '.') }}</span>
                                <span class="ml-space-2 font-medium {{ $row['net_result'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                                    {{ $row['net_result'] >= 0 ? '+' : '' }}{{ number_format($row['net_result'], 0, ',', '.') }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (!empty($snapshot['future_contributions']))
                <div class="text-[10px] text-cryptex-text-tertiary uppercase tracking-[0.05em] mt-space-4 mb-space-2 font-medium">Aportes futuros</div>
                <ul class="flex flex-col gap-space-1 text-fs-12">
                    @foreach ($snapshot['future_contributions'] as $f)
                        <li class="flex justify-between py-1 border-b border-cryptex-border-subtle last:border-0">
                            <span class="text-cryptex-text-secondary">{{ $f['partnership'] }} <span class="mx-1 opacity-50">·</span> <span class="font-mono text-cryptex-text-tertiary">{{ \Illuminate\Support\Carbon::parse($f['date'])->format('d/m/Y') }}</span></span>
                            <span class="font-medium font-mono text-cryptex-text-primary [font-variant-numeric:tabular-nums]">R$ {{ number_format($f['amount'], 2, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        {{-- Faturas próximas --}}
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary flex items-center gap-space-2"><span class="text-fs-18">💳</span> Faturas próximas (30 dias)</h3>
            @if (empty($snapshot['upcoming_invoices']))
                <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma fatura em aberto.</div>
            @else
                <ul class="flex flex-col gap-space-2 text-fs-14">
                    @foreach ($snapshot['upcoming_invoices'] as $row)
                        <li class="flex justify-between items-center py-space-1 border-b border-cryptex-border-subtle last:border-0">
                            <div>
                                <div class="text-cryptex-text-primary">{{ $row['card'] }}</div>
                                <div class="text-fs-12 text-cryptex-text-secondary"><span class="font-mono">{{ $row['reference_month'] }}</span> <span class="mx-1 opacity-50">·</span> venc. <span class="font-mono">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</span></div>
                            </div>
                            <div class="font-medium font-mono text-cryptex-red-500 [font-variant-numeric:tabular-nums]">R$ {{ number_format($row['remaining'], 2, ',', '.') }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>
    </div>

    {{-- Saldos por conta --}}
    <x-fx.card>
        <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">Saldos por conta</h3>
        @if (empty($snapshot['balances_by_account']))
            <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma conta ativa.</div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-4">
                @foreach ($snapshot['balances_by_account'] as $row)
                    <div class="flex justify-between items-center py-space-2 border-b border-cryptex-border-subtle">
                        <span class="text-fs-14 text-cryptex-text-secondary">{{ $row['name'] }}</span>
                        <span class="font-medium font-mono [font-variant-numeric:tabular-nums] {{ $row['balance'] >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">
                            R$ {{ number_format($row['balance'], 2, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-fx.card>

    {{-- Atalhos rápidos --}}
    <div class="flex flex-wrap gap-space-3 mt-space-2">
        <x-fx.button href="{{ route('banking.transactions.create') }}" variant="primary">Lançamento</x-fx.button>
        <x-fx.button href="{{ route('banking.transactions.create', ['type' => 'transfer']) }}" variant="secondary">Transferência</x-fx.button>
        <x-fx.button href="{{ route('investments.operations.index') }}" variant="secondary">Operação</x-fx.button>
        <x-fx.button href="{{ route('writs.create') }}" variant="secondary">Requisitório</x-fx.button>
    </div>
</div>

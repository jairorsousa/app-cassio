<?php

use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-md animate-pulse">
            <div class="h-24 bg-mono-100 rounded-md"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
                <div class="h-28 bg-mono-100 rounded-md"></div>
                <div class="h-28 bg-mono-100 rounded-md"></div>
                <div class="h-28 bg-mono-100 rounded-md"></div>
            </div>
            <div class="h-48 bg-mono-100 rounded-md"></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
                <div class="h-40 bg-mono-100 rounded-md"></div>
                <div class="h-40 bg-mono-100 rounded-md"></div>
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

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <div class="flex justify-between items-center">
        <div class="text-xxs text-mono-600">
            Última atualização:
            {{ $generatedAt ? \Illuminate\Support\Carbon::parse($generatedAt)->format('d/m/Y H:i') : '—' }}
        </div>
        <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="refresh">↻ Atualizar agora</button>
    </div>

    {{-- Patrimônio total --}}
    <x-fx.card>
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-md">
            <div>
                <div class="text-xxs text-mono-600 uppercase tracking-wide">Patrimônio total consolidado</div>
                <div class="text-3xl font-bold text-mono-900">R$ {{ number_format($snapshot['patrimony']['total'], 2, ',', '.') }}</div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-xs text-xxs text-mono-600 md:flex-1 md:max-w-2xl">
                <div>
                    <div class="uppercase">Caixa</div>
                    <div class="font-semibold text-mono-900 text-sm">R$ {{ number_format($snapshot['patrimony']['cash_balance'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase">Carteira</div>
                    <div class="font-semibold text-mono-900 text-sm">R$ {{ number_format($snapshot['patrimony']['portfolio_market_value'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase">Sociedade</div>
                    <div class="font-semibold text-mono-900 text-sm">R$ {{ number_format($snapshot['patrimony']['partnership_exposed'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase">Requisitórios</div>
                    <div class="font-semibold text-mono-900 text-sm">R$ {{ number_format($snapshot['patrimony']['writs_capital_at_risk'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="uppercase">(−) Faturas</div>
                    <div class="font-semibold text-system-down text-sm">R$ {{ number_format($snapshot['patrimony']['open_invoices_total'], 2, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </x-fx.card>

    {{-- Resultado mensal + comparativos --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Resultado do mês</div>
            <div class="text-xl font-bold {{ $snapshot['month']['result'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($snapshot['month']['result'], 2, ',', '.') }}
            </div>
            <div class="text-xxs text-mono-600 mt-xxxs">
                Receita: R$ {{ number_format($snapshot['month']['income'], 2, ',', '.') }}
                · Despesa: R$ {{ number_format($snapshot['month']['expense'], 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Médias móveis (resultado)</div>
            <div class="grid grid-cols-3 gap-xs mt-xxs text-sm">
                <div>
                    <div class="text-xxs text-mono-600">3m</div>
                    <div class="font-semibold {{ $snapshot['averages']['last_3_months'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                        R$ {{ number_format($snapshot['averages']['last_3_months'], 0, ',', '.') }}
                    </div>
                </div>
                <div>
                    <div class="text-xxs text-mono-600">6m</div>
                    <div class="font-semibold {{ $snapshot['averages']['last_6_months'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                        R$ {{ number_format($snapshot['averages']['last_6_months'], 0, ',', '.') }}
                    </div>
                </div>
                <div>
                    <div class="text-xxs text-mono-600">12m</div>
                    <div class="font-semibold {{ $snapshot['averages']['last_12_months'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                        R$ {{ number_format($snapshot['averages']['last_12_months'], 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">A pagar / a receber</div>
            <div class="text-md mt-xxs">
                <div class="flex justify-between">
                    <span class="text-mono-600">Pendente a pagar</span>
                    <span class="font-semibold text-system-down">R$ {{ number_format($snapshot['pending']['payable'], 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-mono-600">Pendente a receber</span>
                    <span class="font-semibold text-system-up">R$ {{ number_format($snapshot['pending']['receivable'], 2, ',', '.') }}</span>
                </div>
                <div class="flex justify-between mt-xxxs pt-xxxs border-t border-mono-100">
                    <span class="text-mono-600">Adiant. corretores em aberto</span>
                    <span class="font-semibold">R$ {{ number_format($snapshot['brokers']['advances_outstanding'] ?? 0, 2, ',', '.') }}</span>
                </div>
            </div>
        </x-fx.card>
    </div>

    {{-- Distribuição --}}
    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Distribuição do patrimônio</h3>
        @php $totalDist = collect($snapshot['distribution'])->sum('value'); @endphp
        @if ($totalDist <= 0)
            <div class="text-sm text-mono-600">Sem dados para distribuir.</div>
        @else
            <ul class="flex flex-col gap-xs">
                @foreach ($snapshot['distribution'] as $slice)
                    @php $pct = $totalDist > 0 ? ($slice['value'] / $totalDist) * 100 : 0; @endphp
                    <li>
                        <div class="flex justify-between text-xs mb-xxxs">
                            <span>{{ $slice['label'] }}</span>
                            <span>R$ {{ number_format($slice['value'], 2, ',', '.') }} ({{ number_format($pct, 1, ',', '.') }}%)</span>
                        </div>
                        <div class="h-2 bg-mono-100 rounded-md overflow-hidden">
                            <div class="h-full bg-primary-500" style="width: {{ $pct }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-fx.card>

    {{-- Indicadores operacionais por módulo --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        {{-- Investimentos --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">📈 Investimentos</h3>
            <div class="grid grid-cols-3 gap-xs text-sm mb-sm">
                <div>
                    <div class="text-xxs text-mono-600">Investido</div>
                    <div class="font-semibold">R$ {{ number_format($snapshot['investments']['invested'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-xxs text-mono-600">Mercado</div>
                    <div class="font-semibold">R$ {{ number_format($snapshot['investments']['market_value'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-xxs text-mono-600">Proventos 12m</div>
                    <div class="font-semibold text-system-up">R$ {{ number_format($snapshot['investments']['dividends_12m'], 2, ',', '.') }}</div>
                </div>
            </div>
            @if (!empty($snapshot['investments']['by_class']))
                <div class="text-xxs text-mono-600 uppercase mb-xxs">Por classe</div>
                <ul class="flex flex-col gap-xxxs text-xs">
                    @foreach ($snapshot['investments']['by_class'] as $class => $row)
                        <li class="flex justify-between">
                            <span>{{ $class }}</span>
                            <span class="font-medium">R$ {{ number_format($row['market_value'], 2, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        {{-- Requisitórios --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">⚖️ Requisitórios</h3>
            <div class="grid grid-cols-2 gap-xs text-sm mb-sm">
                <div>
                    <div class="text-xxs text-mono-600">Capital em risco</div>
                    <div class="font-semibold">R$ {{ number_format($snapshot['writs']['capital_at_risk'], 2, ',', '.') }}</div>
                </div>
                <div>
                    <div class="text-xxs text-mono-600">Lucro líquido esperado</div>
                    <div class="font-semibold text-system-up">R$ {{ number_format($snapshot['writs']['expected_net_profit'], 2, ',', '.') }}</div>
                </div>
            </div>
            <div class="text-xxs text-mono-600 uppercase mb-xxs">Por etapa</div>
            <ul class="flex flex-col gap-xxxs text-xs">
                @foreach ($snapshot['writs']['by_stage'] as $row)
                    <li class="flex justify-between">
                        <span>{{ $row['label'] }}</span>
                        <span class="font-medium">{{ $row['count'] }} · R$ {{ number_format($row['face_total'], 0, ',', '.') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-fx.card>

        {{-- Sociedade --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">🏢 Sociedade</h3>
            @if (empty($snapshot['partnerships']['list']))
                <div class="text-sm text-mono-600">Nenhuma sociedade ativa.</div>
            @else
                <ul class="flex flex-col gap-xxs text-sm">
                    @foreach ($snapshot['partnerships']['list'] as $row)
                        <li class="flex justify-between py-xxxs border-b border-mono-100">
                            <span>{{ $row['name'] }}</span>
                            <span>
                                <span class="text-xxs text-mono-600">exp.</span>
                                R$ {{ number_format($row['exposed'], 2, ',', '.') }}
                                <span class="ml-xxs font-semibold {{ $row['net_result'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                                    {{ $row['net_result'] >= 0 ? '+' : '' }}{{ number_format($row['net_result'], 0, ',', '.') }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (!empty($snapshot['future_contributions']))
                <div class="text-xxs text-mono-600 uppercase mt-sm mb-xxs">Aportes futuros</div>
                <ul class="flex flex-col gap-xxxs text-xs">
                    @foreach ($snapshot['future_contributions'] as $f)
                        <li class="flex justify-between">
                            <span>{{ $f['partnership'] }} · {{ \Illuminate\Support\Carbon::parse($f['date'])->format('d/m/Y') }}</span>
                            <span class="font-medium">R$ {{ number_format($f['amount'], 2, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>

        {{-- Faturas próximas --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">💳 Faturas próximas (30 dias)</h3>
            @if (empty($snapshot['upcoming_invoices']))
                <div class="text-sm text-mono-600">Nenhuma fatura em aberto.</div>
            @else
                <ul class="flex flex-col gap-xxs text-sm">
                    @foreach ($snapshot['upcoming_invoices'] as $row)
                        <li class="flex justify-between py-xxxs border-b border-mono-100">
                            <div>
                                <div>{{ $row['card'] }}</div>
                                <div class="text-xxs text-mono-600">{{ $row['reference_month'] }} · venc. {{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</div>
                            </div>
                            <div class="font-semibold">R$ {{ number_format($row['remaining'], 2, ',', '.') }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fx.card>
    </div>

    {{-- Saldos por conta --}}
    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Saldos por conta</h3>
        @if (empty($snapshot['balances_by_account']))
            <div class="text-sm text-mono-600">Nenhuma conta ativa.</div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-xs">
                @foreach ($snapshot['balances_by_account'] as $row)
                    <div class="flex justify-between py-xxs border-b border-mono-100">
                        <span class="text-sm">{{ $row['name'] }}</span>
                        <span class="font-semibold {{ $row['balance'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                            R$ {{ number_format($row['balance'], 2, ',', '.') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-fx.card>

    {{-- Atalhos rápidos --}}
    <div class="flex flex-wrap gap-xs">
        <x-fx.button href="{{ route('banking.transactions.create') }}" variant="primary">+ Lançamento</x-fx.button>
        <x-fx.button href="{{ route('banking.transactions.create', ['type' => 'transfer']) }}" variant="standard">Transferência</x-fx.button>
        <x-fx.button href="{{ route('investments.operations.index') }}" variant="standard">+ Operação</x-fx.button>
        <x-fx.button href="{{ route('writs.create') }}" variant="standard">+ Requisitório</x-fx.button>
    </div>
</div>

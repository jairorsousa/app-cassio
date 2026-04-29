<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $period = 'all';

    public function with(): array
    {
        $queryCommissions = BrokerCommission::query();
        $queryAdvances = BrokerAdvance::query();

        if ($this->period === 'this_month') {
            $queryCommissions->whereMonth('reference_date', now()->month)
                ->whereYear('reference_date', now()->year);
            $queryAdvances->whereMonth('date', now()->month)
                ->whereYear('date', now()->year);
        } elseif ($this->period === 'last_month') {
            $queryCommissions->whereMonth('reference_date', now()->subMonth()->month)
                ->whereYear('reference_date', now()->subMonth()->year);
            $queryAdvances->whereMonth('date', now()->subMonth()->month)
                ->whereYear('date', now()->subMonth()->year);
        } elseif ($this->period === 'this_year') {
            $queryCommissions->whereYear('reference_date', now()->year);
            $queryAdvances->whereYear('date', now()->year);
        }

        // Resumo geral
        $totalCommissions = (clone $queryCommissions)->sum('commission_amount');
        $totalPendingCommissions = (clone $queryCommissions)->where('status', 'pending')->sum('commission_amount');
        $totalAdvances = (clone $queryAdvances)->sum('amount');
        
        // Comissões pagas no período
        $paidCommissions = (clone $queryCommissions)->whereIn('status', ['paid', 'partially_paid'])
            ->with('broker', 'caseType')
            ->orderByDesc('reference_date')
            ->get();

        // Adiantamentos no período
        $advances = (clone $queryAdvances)->with('broker')->orderByDesc('date')->get();
        $openAdvancesBalance = $advances->sum(fn($a) => $a->remainingBalance());

        // Por corretor (apenas comissões no período)
        $brokers = Broker::with(['commissions' => function($q) {
            if ($this->period === 'this_month') {
                $q->whereMonth('reference_date', now()->month)->whereYear('reference_date', now()->year);
            } elseif ($this->period === 'last_month') {
                $q->whereMonth('reference_date', now()->subMonth()->month)->whereYear('reference_date', now()->subMonth()->year);
            } elseif ($this->period === 'this_year') {
                $q->whereYear('reference_date', now()->year);
            }
        }])->get()->filter(fn($b) => $b->commissions->isNotEmpty());

        return compact(
            'totalCommissions', 'totalPendingCommissions', 
            'totalAdvances', 'openAdvancesBalance', 
            'paidCommissions', 'advances', 'brokers'
        );
    }
}; ?>

<x-slot name="header">Relatórios de Corretores</x-slot>

<div class="flex flex-col gap-md">
    <x-fx.card>
        <div class="flex gap-sm items-end">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Período</label>
                <select wire:model.live="period" class="fx-form-field">
                    <option value="all">Todo o histórico</option>
                    <option value="this_month">Este mês</option>
                    <option value="last_month">Mês passado</option>
                    <option value="this_year">Este ano</option>
                </select>
            </div>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Comissões (Total)</div>
            <div class="text-xl font-bold">R$ {{ number_format($totalCommissions, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Comissões Pendentes</div>
            <div class="text-xl font-bold {{ $totalPendingCommissions > 0 ? 'text-system-down' : '' }}">
                R$ {{ number_format($totalPendingCommissions, 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Adiantamentos (Total)</div>
            <div class="text-xl font-bold">R$ {{ number_format($totalAdvances, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Adiantamentos a Compensar</div>
            <div class="text-xl font-bold text-system-up">
                R$ {{ number_format($openAdvancesBalance, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Comissões Pagas no Período</h3>
            @if ($paidCommissions->isEmpty())
                <div class="text-sm text-mono-600">Nenhuma comissão paga no período selecionado.</div>
            @else
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Data</th><th class="text-left">Corretor</th><th class="text-right">Comissão</th></tr></thead>
                    <tbody>
                        @foreach ($paidCommissions as $com)
                            <tr>
                                <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                                <td>{{ $com->broker->name }}</td>
                                <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fx.card>

        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Resumo por Corretor (No período)</h3>
            @if ($brokers->isEmpty())
                <div class="text-sm text-mono-600">Nenhum corretor com comissões no período selecionado.</div>
            @else
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Corretor</th><th class="text-right">Comissões (Qtd)</th><th class="text-right">Valor Total</th></tr></thead>
                    <tbody>
                        @foreach ($brokers as $b)
                            <tr>
                                <td>
                                    <a href="{{ route('brokers.show', $b) }}" class="font-medium hover:text-primary-500">{{ $b->name }}</a>
                                </td>
                                <td class="text-right">{{ $b->commissions->count() }}</td>
                                <td class="text-right font-semibold">R$ {{ number_format($b->commissions->sum('commission_amount'), 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fx.card>
    </div>
</div>

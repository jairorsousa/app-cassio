<?php

use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Services\PartnershipProfitabilityService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
    }

    public function with(PartnershipProfitabilityService $service): array
    {
        return [
            'summary' => $service->summary($this->partnership),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }}</x-slot>

<div class="flex flex-col gap-md">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Aportado</div>
            <div class="text-xl font-bold">R$ {{ number_format($summary['total_contributed'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Despesas suportadas</div>
            <div class="text-xl font-bold">R$ {{ number_format($summary['total_expenses'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Distribuído</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($summary['total_distributions'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Resultado / ROI</div>
            <div class="text-xl font-bold {{ $summary['net_result'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($summary['net_result'], 2, ',', '.') }}
            </div>
            <div class="text-xxs text-mono-600 mt-xxxs">{{ number_format($summary['roi_percent'], 2, ',', '.') }}% · {{ number_format($summary['monthly_roi_percent'], 3, ',', '.') }}% a.m.</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <div class="grid grid-cols-2 gap-xs text-sm">
            <div><span class="text-mono-600">CNPJ:</span> {{ $partnership->cnpj ?? '—' }}</div>
            <div><span class="text-mono-600">Participação:</span> {{ number_format((float) $partnership->participation_percentage, 2, ',', '.') }}%</div>
            <div><span class="text-mono-600">Entrada:</span> {{ $partnership->joined_at?->format('d/m/Y') ?? '—' }}</div>
            <div><span class="text-mono-600">Status:</span> {{ $partnership->status ? 'Ativa' : 'Inativa' }}</div>
        </div>

        <div class="mt-md flex flex-wrap gap-xs">
            <x-fx.button href="{{ route('partnership.contributions.index', $partnership) }}" variant="standard">Aportes</x-fx.button>
            <x-fx.button href="{{ route('partnership.expenses.index', $partnership) }}" variant="standard">Despesas</x-fx.button>
            <x-fx.button href="{{ route('partnership.distributions.index', $partnership) }}" variant="standard">Distribuições</x-fx.button>
            <x-fx.button href="{{ route('partnership.reports', $partnership) }}" variant="standard">Rentabilidade</x-fx.button>
            <a href="{{ route('partnership.edit', $partnership) }}" class="fx-btn fx-btn--text">Editar dados</a>
            <a href="{{ route('partnership.index') }}" class="fx-btn fx-btn--text">← Lista</a>
        </div>
    </x-fx.card>
</div>

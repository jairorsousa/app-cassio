<?php

use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Services\PartnershipProfitabilityService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
        if ($this->from === '') {
            $this->from = ($partnership->joined_at?->format('Y-m-d')) ?: now()->subYear()->format('Y-m-d');
            $this->to = now()->format('Y-m-d');
        }
    }

    public function with(PartnershipProfitabilityService $service): array
    {
        return [
            'summary' => $service->summary($this->partnership, Carbon::parse($this->from), Carbon::parse($this->to)),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }} · Rentabilidade</x-slot>

<div class="flex flex-col gap-md">
    <x-fx.card>
        <div class="grid grid-cols-2 gap-xs items-end">
            <x-fx.input label="De" type="date" wire:model.live="from" />
            <x-fx.input label="Até" type="date" wire:model.live="to" />
        </div>
    </x-fx.card>

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
            <div class="text-xxs text-mono-600 uppercase">Resultado líquido</div>
            <div class="text-xl font-bold {{ $summary['net_result'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($summary['net_result'], 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-md text-sm">
            <div>
                <div class="text-xxs text-mono-600 uppercase">Capital total exposto</div>
                <div class="text-lg font-semibold">R$ {{ number_format($summary['total_invested'], 2, ',', '.') }}</div>
                <div class="text-xxs text-mono-600">Aportes + despesas suportadas</div>
            </div>
            <div>
                <div class="text-xxs text-mono-600 uppercase">ROI no período</div>
                <div class="text-lg font-semibold {{ $summary['roi_percent'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                    {{ number_format($summary['roi_percent'], 2, ',', '.') }}%
                </div>
            </div>
            <div>
                <div class="text-xxs text-mono-600 uppercase">ROI ao mês equivalente</div>
                <div class="text-lg font-semibold {{ $summary['monthly_roi_percent'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                    {{ number_format($summary['monthly_roi_percent'], 3, ',', '.') }}%
                </div>
                <div class="text-xxs text-mono-600">{{ $summary['months_elapsed'] }} meses no período</div>
            </div>
        </div>
    </x-fx.card>

    <a href="{{ route('partnership.show', $partnership) }}" class="fx-btn fx-btn--text fx-btn--sm self-start">← Voltar</a>
</div>

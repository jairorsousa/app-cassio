<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public Broker $broker;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
    }

    public function with(): array
    {
        $advances = BrokerAdvance::where('broker_id', $this->broker->id)
            ->with('bankAccount')
            ->orderByDesc('date')
            ->paginate(25);

        return compact('advances');
    }
}; ?>

<x-slot name="header">Adiantamentos · {{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    <div class="flex justify-between items-center">
        <a href="{{ route('brokers.show', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm">← Voltar ao corretor</a>
        <x-fx.button href="{{ route('brokers.advances.create', $broker) }}" variant="primary" size="sm">+ Novo adiantamento</x-fx.button>
    </div>

    <x-fx.card>
        @if ($advances->isEmpty())
            <div class="text-sm text-mono-600">Nenhum adiantamento registrado.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-right">Valor</th>
                        <th class="text-left">Forma pgto</th>
                        <th class="text-left">Conta</th>
                        <th class="text-right">Compensado</th>
                        <th class="text-right">Saldo</th>
                        <th class="text-left">Notas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($advances as $adv)
                        <tr>
                            <td>{{ $adv->date->format('d/m/Y') }}</td>
                            <td class="text-right font-semibold">R$ {{ number_format($adv->amount, 2, ',', '.') }}</td>
                            <td>{{ $adv->payment_method ?: '—' }}</td>
                            <td>{{ $adv->bankAccount?->name ?: '—' }}</td>
                            <td class="text-right text-system-up">R$ {{ number_format($adv->settledAmount(), 2, ',', '.') }}</td>
                            <td class="text-right {{ $adv->remainingBalance() > 0 ? 'text-system-down font-semibold' : '' }}">
                                R$ {{ number_format($adv->remainingBalance(), 2, ',', '.') }}
                            </td>
                            <td class="text-xxs text-mono-600 truncate max-w-[200px]">{{ $adv->notes ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-md">{{ $advances->links() }}</div>
        @endif
    </x-fx.card>
</div>

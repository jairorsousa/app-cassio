<?php

use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Services\PartnershipLedgerService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public function delete(int $id, PartnershipLedgerService $ledger): void
    {
        $partnership = Partnership::find($id);
        if (! $partnership) {
            return;
        }

        $ledger->deletePartnership($partnership);
        session()->flash('status', 'Sociedade excluída.');
    }

    public function with(): array
    {
        return [
            'partnerships' => Partnership::orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Sociedade · Lista</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    <x-fx.card>
        <div class="flex justify-between items-center mb-sm">
            <h3 class="text-md font-semibold">Sociedades cadastradas</h3>
            <x-fx.button href="{{ route('partnership.create') }}" variant="primary" size="sm">+ Nova sociedade</x-fx.button>
        </div>

        @if ($partnerships->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma sociedade cadastrada.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">CNPJ</th>
                        <th class="text-right">Participação</th>
                        <th class="text-right">Aportado</th>
                        <th class="text-right">Distribuído</th>
                        <th class="text-right">Resultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($partnerships as $p)
                        @php $net = $p->netResult(); @endphp
                        <tr>
                            <td>
                                <a href="{{ route('partnership.show', $p) }}" class="font-medium hover:text-primary-500">{{ $p->name }}</a>
                                @unless($p->status)<span class="text-xxs text-mono-600 ml-xxxs">(inativa)</span>@endunless
                            </td>
                            <td class="text-xxs">{{ $p->cnpj ?? '—' }}</td>
                            <td class="text-right">{{ number_format((float) $p->participation_percentage, 2, ',', '.') }}%</td>
                            <td class="text-right">R$ {{ number_format($p->totalContributed(), 2, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format($p->totalDistributions(), 2, ',', '.') }}</td>
                            <td class="text-right font-semibold {{ $net >= 0 ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($net, 2, ',', '.') }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('partnership.show', $p) }}" class="fx-btn fx-btn--text fx-btn--sm">Abrir</a>
                                <a href="{{ route('partnership.edit', $p) }}" class="fx-btn fx-btn--text fx-btn--sm">Editar</a>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $p->id }})" wire:confirm="Excluir sociedade? Aportes, despesas, distribuições e lançamentos no caixa também serão excluídos.">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>
</div>

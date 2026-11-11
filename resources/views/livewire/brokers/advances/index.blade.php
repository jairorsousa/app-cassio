<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Support\ColumnSorter;
use App\Support\Concerns\SortsColumns;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    use SortsColumns;

    public Broker $broker;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->loadSort();
    }

    protected function sortListKey(): string
    {
        return 'broker_advances_index';
    }

    public function with(): array
    {
        [$by, $dir] = $this->sortState();
        $all = BrokerAdvance::where('broker_id', $this->broker->id)->with('bankAccount')->orderByDesc('date')->get();
        $advances = ColumnSorter::paginate(ColumnSorter::sort($all, $by, $dir, [
            'date' => fn ($a) => $a->date,
            'amount' => fn ($a) => (float) $a->amount,
            'payment_method' => fn ($a) => $a->payment_method ?: '',
            'account' => fn ($a) => $a->bankAccount?->name ?: '',
            'settled' => fn ($a) => $a->settledAmount(),
            'balance' => fn ($a) => $a->remainingBalance(),
            'notes' => fn ($a) => $a->notes ?: '',
        ], $by === 'date' ? [[fn ($a) => $a->id, $dir]] : [[fn ($a) => $a->date, 'desc'], [fn ($a) => $a->id, 'desc']]), $this, 25);

        return compact('advances');
    }
}; ?>

<x-slot name="header">Adiantamentos · {{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    <div class="flex flex-wrap justify-between items-center gap-3">
        <x-back-button :href="route('brokers.show', $broker)" label="Voltar ao corretor" />
        <x-fx.button href="{{ route('brokers.advances.create', $broker) }}" variant="primary" size="sm">+ Novo adiantamento</x-fx.button>
    </div>

    <x-fx.card>
        @if ($advances->isEmpty())
            <div class="text-sm text-mono-600">Nenhum adiantamento registrado.</div>
        @else
            <x-fx.sort-mobile list="broker_advances_index" :sort-by="$sortBy" :sort-dir="$sortDir" primary />
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <x-fx.sort-th column="date" label="Data" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="amount" label="Valor" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="payment_method" label="Forma pgto" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="account" label="Conta" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="settled" label="Compensado" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="balance" label="Saldo" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="notes" label="Notas" :sort-by="$sortBy" :sort-dir="$sortDir" />
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

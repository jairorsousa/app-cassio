<?php

use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Contacts\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $status = '';

    public function delete(int $id): void
    {
        $contact = Contact::where('type', 'corretor')->findOrFail($id);

        if (! $contact->canBeDeleted()) {
            session()->flash('error', $contact->deletionBlockMessage());
            return;
        }

        $contact->delete();
        session()->flash('status', 'Contato corretor removido.');
    }

    public function with(): array
    {
        $totalAdvances = (float) BrokerAdvance::sum('amount');
        $totalSettledAdvances = (float) BrokerCommissionSettlement::sum('amount_offset');

        $q = Contact::query()->where('type', 'corretor');
        if ($this->search) {
            $q->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('document', 'like', '%'.$this->search.'%');
            });
        }
        if ($this->status !== '') {
            $q->where('status', $this->status === '1');
        }

        return [
            'brokers' => $q->orderBy('name')->paginate(25),
            'summary' => [
                'total_brokers' => Contact::where('type', 'corretor')->count(),
                'active_brokers' => Contact::where('type', 'corretor')->where('status', true)->count(),
                'total_commissions' => (float) BrokerCommission::sum('commission_amount'),
                'paid_commissions' => (float) BrokerCommission::where('status', 'paid')->sum('commission_amount'),
                'total_advances' => $totalAdvances,
                'open_advances' => max($totalAdvances - $totalSettledAdvances, 0),
            ],
        ];
    }
}; ?>

<x-slot name="header">Corretores</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex flex-wrap items-end gap-xs">
            <x-fx.input label="Buscar" wire:model.live.debounce.500ms="search" placeholder="Nome ou CPF/CNPJ..." />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model.live="status" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="1">Ativos</option>
                    <option value="0">Inativos</option>
                </select>
            </div>
            <x-fx.button href="{{ route('banking.transactions.create', ['type' => 'expense']) }}" variant="primary" size="sm">+ Novo lançamento</x-fx.button>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-md">
        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Corretores cadastrados</div>
                    <div class="mt-xxs text-xl font-bold">{{ number_format($summary['total_brokers'], 0, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">groups</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">{{ number_format($summary['active_brokers'], 0, ',', '.') }} ativos</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Comissões cadastradas</div>
                    <div class="mt-xxs text-xl font-bold">R$ {{ number_format($summary['total_commissions'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">request_quote</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Total lançado no histórico</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Comissões pagas</div>
                    <div class="mt-xxs text-xl font-bold text-system-up">R$ {{ number_format($summary['paid_commissions'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-system-up text-lg">paid</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Marcadas como pagas</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Comissão adiantada</div>
                    <div class="mt-xxs text-xl font-bold">R$ {{ number_format($summary['total_advances'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">payments</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Total de adiantamentos</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">A compensar</div>
                    <div class="mt-xxs text-xl font-bold {{ $summary['open_advances'] > 0 ? 'text-system-down' : 'text-system-up' }}">
                        R$ {{ number_format($summary['open_advances'], 2, ',', '.') }}
                    </div>
                </div>
                <span class="material-icons-outlined {{ $summary['open_advances'] > 0 ? 'text-system-down' : 'text-system-up' }} text-lg">account_balance_wallet</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Adiantamentos em aberto</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        @if ($brokers->isEmpty())
            <div class="text-sm text-mono-600">Nenhum corretor encontrado.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Documento</th>
                        <th class="text-left">Telefone</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($brokers as $broker)
                        <tr>
                            <td>
                                <a href="{{ route('brokers.show', $broker) }}" class="font-medium hover:text-primary-500">{{ $broker->name }}</a>
                            </td>
                            <td>{{ $broker->document ?: '—' }}</td>
                            <td>{{ $broker->phone ?: '—' }}</td>
                            <td class="text-center">
                                <x-fx.badge :variant="$broker->status ? 'up' : 'neutral'">{{ $broker->status ? 'Ativo' : 'Inativo' }}</x-fx.badge>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-xxs">
                                    <a href="{{ route('contacts.edit', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm">Editar</a>
                                    <a href="{{ route('brokers.show', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm">Ver</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-md">{{ $brokers->links() }}</div>
        @endif
    </x-fx.card>
</div>

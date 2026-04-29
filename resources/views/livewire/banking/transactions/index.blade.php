<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\TransactionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url]
    public string $category = '';
    #[Url]
    public string $account = '';
    #[Url]
    public string $status = '';
    #[Url]
    public string $type = '';

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->startOfMonth()->format('Y-m-d');
            $this->to = now()->endOfMonth()->format('Y-m-d');
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'category', 'account', 'status', 'type']);
        $this->from = now()->startOfMonth()->format('Y-m-d');
        $this->to = now()->endOfMonth()->format('Y-m-d');
        $this->resetPage();
    }

    public function delete(int $id, TransactionService $service): void
    {
        try {
            $service->delete(Transaction::findOrFail($id));
            session()->flash('status', 'Lançamento excluído.');
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(): array
    {
        $q = Transaction::with(['category', 'bankAccount', 'creditCard']);

        if ($this->from) $q->where('date', '>=', $this->from);
        if ($this->to) $q->where('date', '<=', $this->to);
        if ($this->category) $q->where('category_id', $this->category);
        if ($this->account) $q->where('bank_account_id', $this->account);
        if ($this->status) $q->where('status', $this->status);
        if ($this->type) $q->where('type', $this->type);

        return [
            'transactions' => $q->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'categories' => Category::orderBy('name')->get(),
            'accounts' => BankAccount::orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro · Lançamentos</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex justify-between items-center mb-sm">
            <h3 class="text-md font-semibold">Filtros</h3>
            <x-fx.button href="{{ route('banking.transactions.create') }}" variant="primary" size="sm">+ Novo lançamento</x-fx.button>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-xs">
            <x-fx.input label="De" type="date" wire:model.live="from" />
            <x-fx.input label="Até" type="date" wire:model.live="to" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model.live="type" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="income">Receita</option>
                    <option value="expense">Despesa</option>
                    <option value="transfer">Transferência</option>
                    <option value="invoice_payment">Pagto fatura</option>
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Categoria</label>
                <select wire:model.live="category" class="fx-form-field">
                    <option value="">Todas</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta</label>
                <select wire:model.live="account" class="fx-form-field">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model.live="status" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="settled">Liquidado</option>
                    <option value="pending">Pendente</option>
                </select>
            </div>
        </div>
        <div class="mt-xs">
            <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="clearFilters">Limpar filtros</button>
        </div>
    </x-fx.card>

    <x-fx.card>
        @if ($transactions->isEmpty())
            <x-fx.empty-state
                icon="📋"
                title="Nenhum lançamento no período"
                description="Ajuste os filtros acima ou registre um novo lançamento."
                actionLabel="+ Novo lançamento"
                :actionHref="route('banking.transactions.create')" />
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Descrição</th>
                        <th class="text-left">Categoria</th>
                        <th class="text-left">Conta</th>
                        <th class="text-right">Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $t)
                        <tr>
                            <td>{{ $t->date->format('d/m/Y') }}</td>
                            <td>
                                {{ $t->description }}
                                @if ($t->isInstallment())
                                    <span class="text-xxs text-mono-600">{{ $t->installment_number }}/{{ $t->installment_total }}</span>
                                @endif
                                @if ($t->isReadOnly())
                                    <span class="fx-badge ml-xxxs">origem: {{ class_basename($t->source_type) }}</span>
                                @endif
                                @if ($t->status === 'pending')
                                    <span class="fx-badge ml-xxxs">pendente</span>
                                @endif
                            </td>
                            <td>{{ $t->category?->name }}</td>
                            <td>{{ $t->bankAccount?->name ?? $t->creditCard?->name }}</td>
                            <td class="text-right font-semibold {{ $t->type === 'income' ? 'text-system-up' : ($t->type === 'transfer' ? '' : 'text-system-down') }}">
                                R$ {{ number_format(abs((float) $t->amount), 2, ',', '.') }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                @unless ($t->isReadOnly())
                                    <a href="{{ route('banking.transactions.edit', $t) }}" class="fx-btn fx-btn--text fx-btn--sm">Editar</a>
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $t->id }})" wire:confirm="Excluir lançamento?">Excluir</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-sm">{{ $transactions->links() }}</div>
        @endif
    </x-fx.card>
</div>

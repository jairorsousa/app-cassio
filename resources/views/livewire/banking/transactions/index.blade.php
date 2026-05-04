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

<div class="flex flex-col gap-space-5">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex justify-between items-center mb-space-4">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Filtros</h3>
            <x-fx.button href="{{ route('banking.transactions.create') }}" variant="primary" size="sm">Novo lançamento</x-fx.button>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-space-3">
            <x-fx.input label="De" type="date" wire:model.live="from" />
            <x-fx.input label="Até" type="date" wire:model.live="to" />
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Tipo</label>
                <select wire:model.live="type" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todos</option>
                    <option value="income">Receita</option>
                    <option value="expense">Despesa</option>
                    <option value="transfer">Transferência</option>
                    <option value="invoice_payment">Pagto fatura</option>
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Categoria</label>
                <select wire:model.live="category" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todas</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Conta</label>
                <select wire:model.live="account" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Status</label>
                <select wire:model.live="status" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todos</option>
                    <option value="settled">Liquidado</option>
                    <option value="pending">Pendente</option>
                </select>
            </div>
        </div>
        <div class="mt-space-3">
            <button class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors" wire:click="clearFilters">Limpar filtros</button>
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
            <x-fx.table :headers="['Data', 'Descrição', 'Categoria', 'Conta', 'Valor', '']">
                @foreach ($transactions as $t)
                    <tr>
                        <td class="px-space-4 py-space-3 text-fs-14 font-mono text-cryptex-text-secondary whitespace-nowrap">{{ $t->date->format('d/m/Y') }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14">
                            <span class="text-cryptex-text-primary">{{ $t->description }}</span>
                            @if ($t->isInstallment())
                                <span class="text-fs-12 text-cryptex-text-tertiary ml-1 font-mono">{{ $t->installment_number }}/{{ $t->installment_total }}</span>
                            @endif
                            @if ($t->isReadOnly())
                                <x-fx.badge variant="neutral" class="ml-space-2">origem: {{ class_basename($t->source_type) }}</x-fx.badge>
                            @endif
                            @if ($t->status === 'pending')
                                <x-fx.badge variant="warning" class="ml-space-2">pendente</x-fx.badge>
                            @endif
                        </td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $t->category?->name }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $t->bankAccount?->name ?? $t->creditCard?->name }}</td>
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums] {{ $t->type === 'income' ? 'text-cryptex-green-500' : ($t->type === 'transfer' ? 'text-cryptex-text-primary' : 'text-cryptex-red-500') }}">
                            R$ {{ number_format(abs((float) $t->amount), 2, ',', '.') }}
                        </td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            @unless ($t->isReadOnly())
                                <a href="{{ route('banking.transactions.edit', $t) }}" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">Editar</a>
                                <button class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $t->id }})" wire:confirm="Excluir lançamento?">Excluir</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-fx.table>
            <div class="mt-space-4">{{ $transactions->links() }}</div>
        @endif
    </x-fx.card>
</div>

<?php

use App\Domains\Banking\Models\BankAccount;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showFormModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $bank = '';

    public string $agency = '';

    public string $number = '';

    public string $type = 'checking';

    public string $initial_balance = '0';

    public bool $status = true;

    public string $notes = '';

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'bank' => 'nullable|string|max:120',
            'agency' => 'nullable|string|max:20',
            'number' => 'nullable|string|max:30',
            'type' => 'required|in:checking,savings,investment,cash',
            'initial_balance' => 'required|numeric',
            'status' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $a = BankAccount::findOrFail($id);
        $this->editingId = $a->id;
        $this->name = $a->name;
        $this->bank = (string) $a->bank;
        $this->agency = (string) $a->agency;
        $this->number = (string) $a->number;
        $this->type = $a->type;
        $this->initial_balance = (string) $a->initial_balance;
        $this->status = (bool) $a->status;
        $this->notes = (string) $a->notes;
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            BankAccount::find($this->editingId)?->update($data);
        } else {
            BankAccount::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Conta salva.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        BankAccount::find($id)?->delete();
        session()->flash('status', 'Conta excluída.');
    }

    private function resetForm(): void
    {
        $this->reset(['showFormModal', 'editingId', 'name', 'bank', 'agency', 'number', 'type', 'initial_balance', 'status', 'notes']);
        $this->type = 'checking';
        $this->initial_balance = '0';
        $this->status = true;
        $this->resetValidation();
    }

    public function with(): array
    {
        return ['accounts' => BankAccount::orderBy('name')->get()];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-space-5">
    <x-banking.subnav />

    <x-fx.card>
        <div class="mb-space-4 flex items-center justify-between">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Contas bancárias</h3>
            <x-jr.button type="button" size="sm" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Nova conta
            </x-jr.button>
        </div>

        @if ($accounts->isEmpty())
            <x-fx.empty-state
                icon="🏦"
                title="Nenhuma conta cadastrada"
                description="Cadastre suas contas correntes, poupança e caixa para começar a registrar lançamentos.">
                <x-jr.button type="button" class="mt-space-4" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Nova conta
                </x-jr.button>
            </x-fx.empty-state>
        @else
            <x-fx.table :headers="['Nome', 'Banco', 'Tipo', 'Saldo', '']">
                @foreach ($accounts as $a)
                    <tr>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-primary whitespace-nowrap">{{ $a->name }} @unless($a->status)<span class="text-fs-12 text-cryptex-text-secondary ml-1">(inativa)</span>@endunless</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $a->bank }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ ['checking'=>'Corrente','savings'=>'Poupança','investment'=>'Investimento','cash'=>'Caixa'][$a->type] }}</td>
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums] {{ $a->balance() >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">R$ {{ number_format($a->balance(), 2, ',', '.') }}</td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            <button class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3" wire:click="edit({{ $a->id }})">Editar</button>
                            <button class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $a->id }})" wire:confirm="Excluir conta?">Excluir</button>
                        </td>
                    </tr>
                @endforeach
            </x-fx.table>
        @endif
    </x-fx.card>

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Conta' : 'Nova Conta' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados da conta</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <x-jr.input label="Nome *" icon="badge" name="name" wire:model="name" required />
                                    <x-jr.input label="Banco" icon="account_balance" name="bank" wire:model="bank" />
                                    <x-jr.input label="Agência" icon="confirmation_number" name="agency" wire:model="agency" />
                                    <x-jr.input label="Conta" icon="credit_card" name="number" wire:model="number" />
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                                        <select wire:model="type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="checking">Corrente</option>
                                            <option value="savings">Poupança</option>
                                            <option value="investment">Investimento</option>
                                            <option value="cash">Caixa</option>
                                        </select>
                                        @error('type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                    <x-jr.input label="Saldo inicial *" icon="payments" name="initial_balance" wire:model="initial_balance" x-money required />
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">tune</span>
                                    <h4 class="text-base font-bold text-mono-900">Outros</h4>
                                </div>

                                <div class="space-y-4">
                                    <label class="flex w-fit cursor-pointer items-center gap-3 text-sm">
                                        <input type="checkbox" wire:model="status" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                                        <span class="font-medium text-mono-900">Conta ativa</span>
                                    </label>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
                                        <textarea wire:model="notes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3"></textarea>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            {{ $editingId ? 'Salvar Conta' : 'Criar Conta' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

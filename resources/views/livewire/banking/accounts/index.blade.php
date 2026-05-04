<?php

use App\Domains\Banking\Models\BankAccount;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
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

    public function cancel(): void { $this->resetForm(); }

    public function delete(int $id): void
    {
        BankAccount::find($id)?->delete();
        session()->flash('status', 'Conta excluída.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'bank', 'agency', 'number', 'type', 'initial_balance', 'status', 'notes']);
        $this->type = 'checking';
        $this->initial_balance = '0';
        $this->status = true;
    }

    public function with(): array
    {
        return ['accounts' => BankAccount::orderBy('name')->get()];
    }
}; ?>

<x-slot name="header">Financeiro · Contas Bancárias</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-space-5">
    <x-fx.card class="lg:col-span-2">
        @if ($accounts->isEmpty())
            <x-fx.empty-state
                icon="🏦"
                title="Nenhuma conta cadastrada"
                description="Cadastre suas contas correntes, poupança e caixa para começar a registrar lançamentos." />
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

    <x-fx.card>
        <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">{{ $editingId ? 'Editar' : 'Nova' }} conta</h3>
        <form wire:submit="save" class="flex flex-col gap-space-4">
            <x-fx.input label="Nome" wire:model="name" required />
            <x-fx.input label="Banco" wire:model="bank" />
            <div class="grid grid-cols-2 gap-space-3">
                <x-fx.input label="Agência" wire:model="agency" />
                <x-fx.input label="Conta" wire:model="number" />
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Tipo</label>
                <select wire:model="type" class="w-full h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="checking">Corrente</option>
                    <option value="savings">Poupança</option>
                    <option value="investment">Investimento</option>
                    <option value="cash">Caixa</option>
                </select>
            </div>
            <x-fx.input label="Saldo inicial" type="text" x-money wire:model="initial_balance" numeric />
            <label class="flex items-center gap-space-3 text-fs-14 text-cryptex-text-primary mt-space-2">
                <x-fx.toggle wire:model="status" /> Ativa
            </label>
            <div class="flex flex-col gap-space-1 mt-space-2">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Observações</label>
                <textarea wire:model="notes" class="w-full py-space-3 px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors" rows="2"></textarea>
            </div>
            <div class="flex gap-space-3 mt-space-4">
                <x-fx.button type="submit" variant="primary">Salvar</x-fx.button>
                @if ($editingId)
                    <x-fx.button type="button" variant="ghost" wire:click="cancel">Cancelar</x-fx.button>
                @endif
            </div>
        </form>
    </x-fx.card>
</div>

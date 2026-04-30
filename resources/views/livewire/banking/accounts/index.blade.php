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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if ($accounts->isEmpty())
            <x-fx.empty-state
                icon="🏦"
                title="Nenhuma conta cadastrada"
                description="Cadastre suas contas correntes, poupança e caixa para começar a registrar lançamentos." />
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Banco</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Saldo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr>
                            <td>{{ $a->name }} @unless($a->status)<span class="text-xxs text-mono-600">(inativa)</span>@endunless</td>
                            <td>{{ $a->bank }}</td>
                            <td>{{ ['checking'=>'Corrente','savings'=>'Poupança','investment'=>'Investimento','cash'=>'Caixa'][$a->type] }}</td>
                            <td class="text-right font-semibold">R$ {{ number_format($a->balance(), 2, ',', '.') }}</td>
                            <td class="text-right">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $a->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $a->id }})" wire:confirm="Excluir conta?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} conta</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Nome" wire:model="name" required />
            <x-fx.input label="Banco" wire:model="bank" />
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Agência" wire:model="agency" />
                <x-fx.input label="Conta" wire:model="number" />
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model="type" class="fx-form-field">
                    <option value="checking">Corrente</option>
                    <option value="savings">Poupança</option>
                    <option value="investment">Investimento</option>
                    <option value="cash">Caixa</option>
                </select>
            </div>
            <x-fx.input label="Saldo inicial" type="text" x-money wire:model="initial_balance" />
            <label class="flex items-center gap-xs text-sm">
                <input type="checkbox" wire:model="status" /> Ativa
            </label>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
                <textarea wire:model="notes" class="fx-form-field" rows="2"></textarea>
            </div>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
    </x-fx.card>
</div>

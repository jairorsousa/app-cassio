<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $brand = '';
    public string $bank = '';
    public string $limit = '0';
    public int $closing_day = 1;
    public int $due_day = 10;
    public ?int $default_payment_account_id = null;
    public bool $status = true;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'brand' => 'nullable|string|max:60',
            'bank' => 'nullable|string|max:120',
            'limit' => 'required|numeric|min:0',
            'closing_day' => 'required|integer|min:1|max:31',
            'due_day' => 'required|integer|min:1|max:31',
            'default_payment_account_id' => 'nullable|exists:bank_accounts,id',
            'status' => 'boolean',
        ];
    }

    public function edit(int $id): void
    {
        $c = CreditCard::findOrFail($id);
        $this->editingId = $c->id;
        $this->name = $c->name;
        $this->brand = (string) $c->brand;
        $this->bank = (string) $c->bank;
        $this->limit = (string) $c->limit;
        $this->closing_day = $c->closing_day;
        $this->due_day = $c->due_day;
        $this->default_payment_account_id = $c->default_payment_account_id;
        $this->status = (bool) $c->status;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            CreditCard::find($this->editingId)?->update($data);
        } else {
            CreditCard::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Cartão salvo.');
    }

    public function cancel(): void { $this->resetForm(); }

    public function delete(int $id): void
    {
        CreditCard::find($id)?->delete();
        session()->flash('status', 'Cartão excluído.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'brand', 'bank', 'limit', 'closing_day', 'due_day', 'default_payment_account_id', 'status']);
        $this->closing_day = 1;
        $this->due_day = 10;
        $this->status = true;
        $this->limit = '0';
    }

    public function with(): array
    {
        return [
            'cards' => CreditCard::orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro · Cartões de Crédito</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if ($cards->isEmpty())
            <x-fx.empty-state
                icon="💳"
                title="Nenhum cartão de crédito cadastrado"
                description="Cadastre seus cartões para que faturas e parcelas sejam controladas automaticamente." />
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Bandeira</th>
                        <th class="text-right">Limite</th>
                        <th class="text-center">Fech./Venc.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cards as $c)
                        <tr>
                            <td>{{ $c->name }} @unless($c->status)<span class="text-xxs text-mono-600">(inativo)</span>@endunless</td>
                            <td>{{ $c->brand }}</td>
                            <td class="text-right">R$ {{ number_format($c->limit, 2, ',', '.') }}</td>
                            <td class="text-center text-xxs">dia {{ $c->closing_day }} / dia {{ $c->due_day }}</td>
                            <td class="text-right">
                                <a href="{{ route('banking.cards.invoices', $c) }}" class="fx-btn fx-btn--text fx-btn--sm">Faturas</a>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $c->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $c->id }})" wire:confirm="Excluir cartão?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Novo' }} cartão</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Nome" wire:model="name" required />
            <x-fx.input label="Bandeira" wire:model="brand" />
            <x-fx.input label="Banco emissor" wire:model="bank" />
            <x-fx.input label="Limite" type="number" step="0.01" wire:model="limit" />
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Dia fechamento" type="number" min="1" max="31" wire:model="closing_day" />
                <x-fx.input label="Dia vencimento" type="number" min="1" max="31" wire:model="due_day" />
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta de pagamento padrão</label>
                <select wire:model="default_payment_account_id" class="fx-form-field">
                    <option value="">— nenhuma —</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-xs text-sm">
                <input type="checkbox" wire:model="status" /> Ativo
            </label>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
    </x-fx.card>
</div>

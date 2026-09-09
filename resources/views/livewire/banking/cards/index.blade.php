<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showFormModal = false;

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
            CreditCard::find($this->editingId)?->update($data);
        } else {
            CreditCard::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Cartão salvo.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        CreditCard::find($id)?->delete();
        session()->flash('status', 'Cartão excluído.');
    }

    private function resetForm(): void
    {
        $this->reset(['showFormModal', 'editingId', 'name', 'brand', 'bank', 'limit', 'closing_day', 'due_day', 'default_payment_account_id', 'status']);
        $this->closing_day = 1;
        $this->due_day = 10;
        $this->status = true;
        $this->limit = '0';
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'cards' => CreditCard::orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-md">
    <x-banking.subnav />

    <x-fx.card>
        <div class="mb-space-4 flex items-center justify-between">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Cartões de crédito</h3>
            <x-jr.button type="button" size="sm" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Novo cartão
            </x-jr.button>
        </div>

        @if ($cards->isEmpty())
            <x-fx.empty-state
                icon="💳"
                title="Nenhum cartão de crédito cadastrado"
                description="Cadastre seus cartões para que faturas e parcelas sejam controladas automaticamente.">
                <x-jr.button type="button" class="mt-space-4" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Novo cartão
                </x-jr.button>
            </x-fx.empty-state>
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

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Cartão' : 'Novo Cartão' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">credit_card</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados do cartão</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <x-jr.input label="Nome *" icon="badge" name="name" wire:model="name" required />
                                    <x-jr.input label="Bandeira" icon="sell" name="brand" wire:model="brand" />
                                    <x-jr.input label="Banco emissor" icon="account_balance" name="bank" wire:model="bank" />
                                    <x-jr.input label="Limite *" icon="payments" name="limit" wire:model="limit" x-money required />
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">event_repeat</span>
                                    <h4 class="text-base font-bold text-mono-900">Fechamento e pagamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <x-jr.input label="Dia do fechamento *" icon="event" name="closing_day" type="number" min="1" max="31" wire:model="closing_day" required />
                                    <x-jr.input label="Dia do vencimento *" icon="event_available" name="due_day" type="number" min="1" max="31" wire:model="due_day" required />
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta de pagamento padrão</label>
                                        <select wire:model="default_payment_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="">Nenhuma</option>
                                            @foreach ($accounts as $accountOption)
                                                <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('default_payment_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">tune</span>
                                    <h4 class="text-base font-bold text-mono-900">Status</h4>
                                </div>
                                <label class="flex w-fit cursor-pointer items-center gap-3 text-sm">
                                    <input type="checkbox" wire:model="status" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                                    <span class="font-medium text-mono-900">Cartão ativo</span>
                                </label>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            {{ $editingId ? 'Salvar Cartão' : 'Criar Cartão' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

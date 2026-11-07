<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CardHolder;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
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

    public ?int $card_holder_id = null;

    public string $newHolderName = '';

    public bool $addingHolder = false;

    public string $holder_type = '';

    public string $holder_name = '';

    public ?int $holder_contact_id = null;

    #[Url]
    public string $titular = '';

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
            'card_holder_id' => ['nullable', 'integer', Rule::exists('card_holders', 'id')],
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
        $this->card_holder_id = $c->card_holder_id;
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
        $data = $this->validate($this->rules());
        // Titular agora vem da lista (card_holders); os campos antigos ficam como estavam.

        if ($this->editingId) {
            CreditCard::find($this->editingId)?->update($data);
        } else {
            CreditCard::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Cartão salvo.');
    }

    /** "+ Novo titular": cadastra o nome na hora e já seleciona no cartão. */
    public function addHolder(): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $this->newHolderName));
        $this->validate(['newHolderName' => 'required|string|max:120'], [], ['newHolderName' => 'nome do titular']);
        $holder = CardHolder::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? CardHolder::create(['name' => $name]);
        $this->card_holder_id = $holder->id;
        $this->newHolderName = '';
        $this->addingHolder = false;
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
        $this->reset(['showFormModal', 'editingId', 'name', 'brand', 'bank', 'limit', 'closing_day', 'due_day', 'default_payment_account_id', 'status', 'card_holder_id', 'newHolderName', 'addingHolder']);
        $this->card_holder_id = CardHolder::defaultId();
        $this->closing_day = 1;
        $this->due_day = 10;
        $this->status = true;
        $this->limit = '0';
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'cards' => CreditCard::with(['holderContact', 'holder', 'invoices'])
                ->when($this->titular !== '', fn ($q) => $q->whereIn('id', CreditCard::idsForHolderKey($this->titular)))
                ->orderBy('name')->get(),
            'holderOptions' => CreditCard::holderFilterOptions(),
            'holders' => CardHolder::orderBy('name')->get(['id', 'name']),
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
            <div class="flex items-center gap-2">
            <select wire:model.live="titular" aria-label="Filtrar por titular" class="h-9 rounded-pill border border-mono-200 bg-mono-white px-3 text-xs">
                <option value="">Todos os titulares</option>
                @foreach ($holderOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <x-jr.button type="button" size="sm" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Novo cartão
            </x-jr.button>
            </div>
        </div>
        @if (session('status'))
            <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
        @endif

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
            <div class="overflow-x-auto">
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Cartão</th>
                        <th class="text-left">Titular</th>
                        <th class="text-right">Limite</th>
                        <th class="text-center">Fech./Venc.</th>
                        <th class="text-right">A pagar</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cards as $c)
                        @php $due = $c->invoices->filter(fn ($i) => $i->status !== 'open')->sum(fn ($i) => $i->remainingAmount()); $late = $c->invoices->contains(fn ($i) => $i->isOverdue()); @endphp
                        <tr>
                            <td>{{ $c->name }} @if($c->brand)<span class="text-xxs text-mono-500">· {{ $c->brand }}</span>@endif @unless($c->status)<span class="text-xxs text-mono-600">(inativo)</span>@endunless</td>
                            <td>@if ($c->hasHolder()){{ $c->holderLabel() }}@else<span class="rounded-pill bg-amber-50 px-2 py-0.5 text-xxs font-semibold text-amber-700">Não informado</span>@endif</td>
                            <td class="text-right whitespace-nowrap">R$ {{ number_format($c->limit, 2, ',', '.') }}</td>
                            <td class="text-center text-xxs whitespace-nowrap">dia {{ $c->closing_day }} / dia {{ $c->due_day }}</td>
                            <td class="text-right whitespace-nowrap {{ $late ? 'font-semibold text-red-700' : '' }}">R$ {{ number_format($due, 2, ',', '.') }}@if ($late) <span class="text-xxs">(vencida)</span>@endif</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('banking.cards.invoices', $c) }}" class="fx-btn fx-btn--text fx-btn--sm">Faturas</a>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $c->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $c->id }})" wire:confirm="Excluir cartão?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
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
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Titular</label>
                                        <div class="flex items-center gap-2">
                                            <select wire:model="card_holder_id" class="h-12 min-w-0 flex-1 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900" data-holder-select>
                                                @if ($card_holder_id === null)<option value="">Não informado</option>@endif
                                                @foreach ($holders as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach
                                            </select>
                                            <button type="button" class="fx-btn fx-btn--mono shrink-0 whitespace-nowrap" wire:click="$set('addingHolder', true)">+ Novo titular</button>
                                        </div>
                                        @if ($addingHolder)
                                            <div class="mt-2 flex gap-2" data-new-holder-form>
                                                <input type="text" wire:model="newHolderName" wire:keydown.enter.prevent="addHolder" placeholder="Nome do titular (ex.: Isabela)" maxlength="120" class="h-10 min-w-0 flex-1 rounded-pill border border-mono-200 px-4 text-sm" />
                                                <button type="button" class="fx-btn fx-btn--primary" wire:click="addHolder">Adicionar</button>
                                                <button type="button" class="fx-btn fx-btn--text" wire:click="$set('addingHolder', false)">Cancelar</button>
                                            </div>
                                            @error('newHolderName') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        @endif
                                        @error('card_holder_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
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
                            Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

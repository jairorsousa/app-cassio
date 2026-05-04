<?php

use App\Domains\Contacts\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Contact $contact = null;

    public string $name = '';
    public string $document = '';
    public string $rg = '';
    public ?string $birth_date = null;
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $bank_name = '';
    public string $bank_agency = '';
    public string $bank_account = '';
    public string $bank_account_type = '';
    public string $pix_key = '';
    public bool $status = true;
    public string $notes = '';

    public function mount(?Contact $contact = null): void
    {
        if ($contact && $contact->exists) {
            $this->contact = $contact;
            foreach (['name', 'document', 'rg', 'phone', 'email', 'address',
                'bank_name', 'bank_agency', 'bank_account', 'bank_account_type',
                'pix_key', 'notes'] as $f) {
                $this->{$f} = (string) ($contact->{$f} ?? '');
            }
            $this->birth_date = $contact->birth_date?->format('Y-m-d');
            $this->status = (bool) $contact->status;
        }
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:200',
            'document'          => 'nullable|string|max:30',
            'rg'                => 'nullable|string|max:30',
            'birth_date'        => 'nullable|date',
            'phone'             => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:200',
            'address'           => 'nullable|string',
            'bank_name'         => 'nullable|string|max:100',
            'bank_agency'       => 'nullable|string|max:20',
            'bank_account'      => 'nullable|string|max:30',
            'bank_account_type' => 'nullable|string|max:20',
            'pix_key'           => 'nullable|string|max:200',
            'status'            => 'boolean',
            'notes'             => 'nullable|string',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if ($this->contact) {
            $this->contact->update($data);
            $contact = $this->contact;
        } else {
            $contact = Contact::create($data);
        }

        session()->flash('status', 'Contato salvo.');
        return $this->redirectRoute('contacts.show', $contact, navigate: true);
    }
}; ?>

<x-slot name="header">{{ $contact ? 'Editar' : 'Novo' }} contato</x-slot>

<x-jr.card class="max-w-5xl">
    <form wire:submit="save" class="flex flex-col gap-8">
        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">person</span>
                <h3 class="text-base font-bold text-mono-900">Dados Pessoais</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-jr.input label="Nome *" icon="badge" name="name" wire:model="name" required />
                <x-jr.input label="CPF/CNPJ" icon="article" name="document" wire:model="document" x-cpf-cnpj />
                <x-jr.input label="RG" icon="fingerprint" name="rg" wire:model="rg" />
                <x-jr.input label="Data de nascimento" icon="calendar_month" name="birth_date" type="date" wire:model="birth_date" />
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">call</span>
                <h3 class="text-base font-bold text-mono-900">Contato</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-jr.input label="Telefone" icon="phone" name="phone" wire:model="phone" x-phone />
                <x-jr.input label="E-mail" icon="mail" name="email" wire:model="email" type="email" />
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-mono-600">Endereço</label>
                    <textarea wire:model="address" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="2"></textarea>
                </div>
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">account_balance</span>
                <h3 class="text-base font-bold text-mono-900">Dados Bancários</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-jr.input label="Banco" icon="account_balance" name="bank_name" wire:model="bank_name" />
                <x-jr.input label="Agência" icon="confirmation_number" name="bank_agency" wire:model="bank_agency" />
                <x-jr.input label="Conta" icon="credit_card" name="bank_account" wire:model="bank_account" />
                <div>
                    <label class="mb-2 block text-sm font-medium text-mono-600">Tipo de conta</label>
                    <select wire:model="bank_account_type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                        <option value="">Selecionar</option>
                        <option value="corrente">Corrente</option>
                        <option value="poupanca">Poupança</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <x-jr.input label="Chave PIX" icon="key" name="pix_key" wire:model="pix_key" />
                </div>
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">tune</span>
                <h3 class="text-base font-bold text-mono-900">Outros</h3>
            </div>
            <div class="flex items-center gap-3">
                <label class="flex w-fit cursor-pointer items-center gap-3 text-sm">
                    <input type="checkbox" wire:model="status" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                    <span class="font-medium text-mono-900">Contato ativo</span>
                </label>
            </div>
        </section>

        <div>
            <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
            <textarea wire:model="notes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3"></textarea>
        </div>

        <div class="flex justify-end gap-3 border-t border-mono-100 pt-4">
            <a href="{{ route('contacts.index') }}" class="h-11 rounded-pill bg-mono-100 px-6 py-3 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200">Cancelar</a>
            <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                <span class="material-icons-outlined text-[18px]">check</span>
                Salvar
            </button>
        </div>
    </form>
</x-jr.card>

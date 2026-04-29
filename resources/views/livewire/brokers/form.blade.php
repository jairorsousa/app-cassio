<?php

use App\Domains\Brokers\Models\Broker;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Broker $broker = null;

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

    public function mount(?Broker $broker = null): void
    {
        if ($broker && $broker->exists) {
            $this->broker = $broker;
            foreach (['name', 'document', 'rg', 'phone', 'email', 'address',
                'bank_name', 'bank_agency', 'bank_account', 'bank_account_type',
                'pix_key', 'notes'] as $f) {
                $this->{$f} = (string) ($broker->{$f} ?? '');
            }
            $this->birth_date = $broker->birth_date?->format('Y-m-d');
            $this->status = (bool) $broker->status;
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'document' => 'nullable|string|max:30',
            'rg' => 'nullable|string|max:30',
            'birth_date' => 'nullable|date',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:200',
            'address' => 'nullable|string',
            'bank_name' => 'nullable|string|max:100',
            'bank_agency' => 'nullable|string|max:20',
            'bank_account' => 'nullable|string|max:30',
            'bank_account_type' => 'nullable|string|max:20',
            'pix_key' => 'nullable|string|max:200',
            'status' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        if ($this->broker) {
            $this->broker->update($data);
            $broker = $this->broker;
        } else {
            $broker = Broker::create($data);
        }

        session()->flash('status', 'Corretor salvo.');
        return $this->redirectRoute('brokers.show', $broker, navigate: true);
    }
}; ?>

<x-slot name="header">{{ $broker ? 'Editar' : 'Novo' }} corretor</x-slot>

<x-fx.card class="max-w-4xl">
    <form wire:submit="save" class="flex flex-col gap-md">
        <section>
            <h3 class="text-md font-semibold mb-xs">Dados Pessoais</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <x-fx.input label="Nome *" wire:model="name" />
                <x-fx.input label="CPF/CNPJ" wire:model="document" />
                <x-fx.input label="RG" wire:model="rg" />
                <x-fx.input label="Data de nascimento" type="date" wire:model="birth_date" />
            </div>
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Contato</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <x-fx.input label="Telefone" wire:model="phone" />
                <x-fx.input label="E-mail" wire:model="email" type="email" />
                <div class="md:col-span-2">
                    <label class="block text-xxs text-mono-600 mb-xxxs">Endereço</label>
                    <textarea wire:model="address" class="fx-form-field" rows="2"></textarea>
                </div>
            </div>
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Dados Bancários</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                <x-fx.input label="Banco" wire:model="bank_name" />
                <x-fx.input label="Agência" wire:model="bank_agency" />
                <x-fx.input label="Conta" wire:model="bank_account" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Tipo de conta</label>
                    <select wire:model="bank_account_type" class="fx-form-field">
                        <option value="">—</option>
                        <option value="corrente">Corrente</option>
                        <option value="poupanca">Poupança</option>
                    </select>
                </div>
                <x-fx.input label="Chave PIX" wire:model="pix_key" />
            </div>
        </section>

        <section>
            <div class="flex items-center gap-sm">
                <label class="flex items-center gap-xxs text-sm">
                    <input type="checkbox" wire:model="status" class="rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                    Corretor ativo
                </label>
            </div>
        </section>

        <div>
            <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
            <textarea wire:model="notes" class="fx-form-field" rows="3"></textarea>
        </div>

        <div class="flex gap-xs">
            <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
            <a href="{{ route('brokers.index') }}" class="fx-btn fx-btn--text">Cancelar</a>
        </div>
    </form>
</x-fx.card>

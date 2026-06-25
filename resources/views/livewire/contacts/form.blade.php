<?php

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\CepLookupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Contact $contact = null;

    #[Url]
    public string $type = 'cedente';

    public string $name = '';
    public string $document = '';
    public ?string $birth_date = null;
    public string $phone = '';
    public array $phones = [''];
    public string $email = '';
    public array $emails = [''];
    public string $address = '';
    public string $zip_code = '';
    public string $street = '';
    public string $number = '';
    public string $complement = '';
    public string $city = '';
    public string $state = '';
    public string $bank_name = '';
    public string $bank_agency = '';
    public string $bank_account = '';
    public string $bank_account_type = '';
    public string $pix_key = '';
    public string $pix_key_type = '';
    public bool $status = true;
    public string $notes = '';

    public function mount(?Contact $contact = null): void
    {
        if ($contact && $contact->exists) {
            $this->contact = $contact;
            foreach (['type', 'name', 'document', 'phone', 'email', 'address',
                'zip_code', 'street', 'number', 'complement', 'city', 'state',
                'bank_name', 'bank_agency', 'bank_account', 'bank_account_type',
                'pix_key', 'pix_key_type', 'notes'] as $f) {
                $this->{$f} = (string) ($contact->{$f} ?? '');
            }
            $this->phones = $this->normalizeRepeaterValues($contact->phones ?: [$contact->phone]);
            $this->emails = $this->normalizeRepeaterValues($contact->emails ?: [$contact->email]);
            $this->birth_date = $contact->birth_date?->format('Y-m-d');
            $this->status = (bool) $contact->status;
        }
    }

    public function rules(): array
    {
        return [
            'type'              => 'required|in:cedente,advogado,corretor',
            'name'              => 'required|string|max:200',
            'document'          => 'nullable|string|max:30',
            'birth_date'        => 'nullable|date',
            'phone'             => 'nullable|string|max:30',
            'phones'            => 'array',
            'phones.*'          => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:200',
            'emails'            => 'array',
            'emails.*'          => 'nullable|email|max:200',
            'address'           => 'nullable|string',
            'zip_code'          => 'nullable|string|max:20',
            'street'            => 'nullable|string|max:255',
            'number'            => 'nullable|string|max:30',
            'complement'        => 'nullable|string|max:255',
            'city'              => 'nullable|string|max:120',
            'state'             => 'nullable|string|size:2',
            'bank_name'         => 'nullable|string|max:100',
            'bank_agency'       => 'nullable|string|max:20',
            'bank_account'      => 'nullable|string|max:30',
            'bank_account_type' => 'nullable|string|max:20',
            'pix_key'           => 'nullable|string|max:200',
            'pix_key_type'      => 'nullable|in:email,cpf,telefone,aleatoria',
            'status'            => 'boolean',
            'notes'             => 'nullable|string',
        ];
    }

    public function addPhone(): void
    {
        $this->phones[] = '';
    }

    public function removePhone(int $index): void
    {
        unset($this->phones[$index]);
        $this->phones = array_values($this->phones);

        if ($this->phones === []) {
            $this->phones = [''];
        }
    }

    public function addEmail(): void
    {
        $this->emails[] = '';
    }

    public function removeEmail(int $index): void
    {
        unset($this->emails[$index]);
        $this->emails = array_values($this->emails);

        if ($this->emails === []) {
            $this->emails = [''];
        }
    }

    public function lookupZipCode(): void
    {
        $result = app(CepLookupService::class)->lookup($this->zip_code);

        if (! $result) {
            $this->addError('zip_code', 'CEP não encontrado.');
            return;
        }

        $this->zip_code = $result['zip_code'];
        $this->street = $result['street'];
        $this->city = $result['city'];
        $this->state = $result['state'];

        if ($result['complement'] && $this->complement === '') {
            $this->complement = $result['complement'];
        }

        $this->syncAddress();
        $this->resetErrorBag('zip_code');
    }

    public function updatedZipCode(): void
    {
        if (strlen(preg_replace('/\D+/', '', $this->zip_code) ?? '') === 8) {
            $this->lookupZipCode();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['street', 'number', 'complement', 'city', 'state', 'zip_code'], true)) {
            $this->syncAddress();
        }
    }

    public function save()
    {
        $this->syncAddress();
        $this->syncContactMethods();
        $data = $this->validate();
        $data['phones'] = $this->cleanRepeaterValues($this->phones);
        $data['emails'] = $this->cleanRepeaterValues($this->emails);
        $data['phone'] = $data['phones'][0] ?? null;
        $data['email'] = $data['emails'][0] ?? null;

        if ($this->contact) {
            $this->contact->update($data);
            $contact = $this->contact;
        } else {
            $contact = Contact::create($data);
        }

        session()->flash('status', 'Contato salvo.');
        return $this->redirectRoute('contacts.show', $contact, navigate: true);
    }

    private function syncAddress(): void
    {
        $line = trim(collect([$this->street, $this->number])->filter()->implode(', '));
        $details = trim(collect([$this->complement, $this->city, $this->state, $this->zip_code])->filter()->implode(' - '));
        $this->address = collect([$line, $details])->filter()->implode(' | ');
    }

    private function syncContactMethods(): void
    {
        $phones = $this->cleanRepeaterValues($this->phones);
        $emails = $this->cleanRepeaterValues($this->emails);

        $this->phone = $phones[0] ?? '';
        $this->email = $emails[0] ?? '';
        $this->phones = $phones === [] ? [''] : $phones;
        $this->emails = $emails === [] ? [''] : $emails;
    }

    private function normalizeRepeaterValues(?array $values): array
    {
        $clean = $this->cleanRepeaterValues($values ?? []);

        return $clean === [] ? [''] : $clean;
    }

    private function cleanRepeaterValues(array $values): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values
        ), fn ($value) => $value !== ''));
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
                <div>
                    <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                    <select wire:model="type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                        <option value="cedente">Cedente</option>
                        <option value="advogado">Advogado</option>
                        <option value="corretor">Corretor</option>
                    </select>
                </div>
                <x-jr.input label="Nome *" icon="badge" name="name" wire:model="name" required />
                <x-jr.input label="CPF/CNPJ" icon="article" name="document" wire:model="document" x-cpf-cnpj />
                <x-jr.input label="Data de nascimento" icon="calendar_month" name="birth_date" type="date" wire:model="birth_date" />
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">call</span>
                <h3 class="text-base font-bold text-mono-900">Contato</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium text-mono-600">Telefones</label>
                        <button type="button" wire:click="addPhone" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-primary-500 transition-colors hover:bg-primary-500 hover:text-white" title="Adicionar telefone">
                            <span class="material-icons-outlined text-[18px]">add</span>
                        </button>
                    </div>
                    @foreach ($phones as $index => $phoneValue)
                        <div class="flex items-center gap-2" wire:key="contact-phone-{{ $index }}">
                            <div class="flex-1">
                                <x-jr.input icon="phone" name="phones.{{ $index }}" wire:model="phones.{{ $index }}" x-phone />
                            </div>
                            @if (count($phones) > 1)
                                <button type="button" wire:click="removePhone({{ $index }})" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-mono-400 transition-colors hover:bg-down-bg hover:text-down" title="Remover telefone">
                                    <span class="material-icons-outlined text-[18px]">remove</span>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <label class="block text-sm font-medium text-mono-600">E-mails</label>
                        <button type="button" wire:click="addEmail" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-primary-500 transition-colors hover:bg-primary-500 hover:text-white" title="Adicionar e-mail">
                            <span class="material-icons-outlined text-[18px]">add</span>
                        </button>
                    </div>
                    @foreach ($emails as $index => $emailValue)
                        <div class="flex items-center gap-2" wire:key="contact-email-{{ $index }}">
                            <div class="flex-1">
                                <x-jr.input icon="mail" name="emails.{{ $index }}" wire:model="emails.{{ $index }}" type="email" />
                            </div>
                            @if (count($emails) > 1)
                                <button type="button" wire:click="removeEmail({{ $index }})" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-mono-400 transition-colors hover:bg-down-bg hover:text-down" title="Remover e-mail">
                                    <span class="material-icons-outlined text-[18px]">remove</span>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section>
            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                <span class="material-icons-outlined text-[20px] text-primary-500">location_on</span>
                <h3 class="text-base font-bold text-mono-900">Endereço</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-jr.input label="CEP" icon="markunread_mailbox" name="zip_code" wire:model.blur="zip_code" x-mask="99999-999" />
                </div>
                <div class="flex items-end md:col-span-1">
                    <button type="button" wire:click="lookupZipCode" class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-pill border border-primary-500 px-4 text-sm font-semibold text-primary-500 transition-colors hover:bg-primary-100">
                        <span class="material-icons-outlined text-[18px]">search</span>
                        Buscar
                    </button>
                </div>
                <div class="md:col-span-3">
                    <x-jr.input label="Cidade" icon="location_city" name="city" wire:model.live="city" />
                </div>
                <div class="md:col-span-4">
                    <x-jr.input label="Endereço" icon="signpost" name="street" wire:model.live="street" />
                </div>
                <x-jr.input label="Número" icon="tag" name="number" wire:model.live="number" />
                <x-jr.input label="Estado" icon="map" name="state" wire:model.live="state" maxlength="2" />
                <div class="md:col-span-6">
                    <x-jr.input label="Complemento" icon="add_home" name="complement" wire:model.live="complement" />
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
                <div>
                    <label class="mb-2 block text-sm font-medium text-mono-600">Tipo da chave PIX</label>
                    <select wire:model="pix_key_type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                        <option value="">Selecionar</option>
                        <option value="email">E-mail</option>
                        <option value="cpf">CPF/CNPJ</option>
                        <option value="telefone">Telefone</option>
                        <option value="aleatoria">Aleatória</option>
                    </select>
                </div>
                <div>
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

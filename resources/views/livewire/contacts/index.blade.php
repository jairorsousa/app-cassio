<?php

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\CepLookupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $status = '';
    #[Url(as: 'type')]
    public string $filterType = '';

    public ?int $editingId = null;
    public bool $showFormModal = false;

    public string $type_form = 'cedente';
    public string $name = '';
    public string $document = '';
    public ?string $birth_date = null;
    public string $phone = '';
    public string $email = '';
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
    public bool $status_form = true;
    public string $notes = '';

    public function rules(): array
    {
        return [
            'type_form'         => 'required|in:cedente,advogado,corretor',
            'name'              => 'required|string|max:200',
            'document'          => 'nullable|string|max:30',
            'birth_date'        => 'nullable|date',
            'phone'             => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:200',
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
            'status_form'       => 'boolean',
            'notes'             => 'nullable|string',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $contact = Contact::findOrFail($id);
        $this->editingId = $contact->id;
        
        foreach (['name', 'document', 'phone', 'email', 'address',
            'zip_code', 'street', 'number', 'complement', 'city', 'state',
            'bank_name', 'bank_agency', 'bank_account', 'bank_account_type',
            'pix_key', 'notes'] as $f) {
            $this->{$f} = (string) ($contact->{$f} ?? '');
        }
        $this->type_form = (string) ($contact->type ?? 'cedente');
        $this->birth_date = $contact->birth_date?->format('Y-m-d');
        $this->status_form = (bool) $contact->status;

        $this->showFormModal = true;
    }

    public function save(): void
    {
        $this->syncAddress();
        $data = $this->validate();
        $data['type'] = $data['type_form'];
        $data['status'] = $data['status_form'];
        unset($data['type_form']);
        unset($data['status_form']);

        if ($this->editingId) {
            Contact::find($this->editingId)?->update($data);
            $msg = 'Contato atualizado com sucesso.';
        } else {
            Contact::create($data);
            $msg = 'Contato criado com sucesso.';
        }

        $this->resetForm();
        session()->flash('status', $msg);
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'document', 'birth_date', 'phone', 'email',
            'address', 'zip_code', 'street', 'number', 'complement', 'city', 'state',
            'bank_name', 'bank_agency', 'bank_account', 'bank_account_type',
            'pix_key', 'notes', 'showFormModal'
        ]);
        $this->type_form = $this->filterType !== '' ? $this->filterType : 'cedente';
        $this->status_form = true;
        $this->resetErrorBag();
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

    private function syncAddress(): void
    {
        $line = trim(collect([$this->street, $this->number])->filter()->implode(', '));
        $details = trim(collect([$this->complement, $this->city, $this->state, $this->zip_code])->filter()->implode(' - '));
        $this->address = collect([$line, $details])->filter()->implode(' | ');
    }

    public function delete(int $id): void
    {
        Contact::findOrFail($id)->delete();
        session()->flash('status', 'Contato removido.');
    }

    public function with(): array
    {
        $q = Contact::query();
        if ($this->search) {
            $q->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('document', 'like', '%'.$this->search.'%');
            });
        }
        if ($this->status !== '') {
            $q->where('status', $this->status === '1');
        }
        if ($this->filterType !== '') {
            $q->where('type', $this->filterType);
        }

        return [
            'contacts' => $q->orderBy('name')->paginate(25),
        ];
    }
}; ?>

<x-slot name="header">Contatos</x-slot>

<div class="flex flex-col gap-6">
    @if (session('status'))
        <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert>
    @endif

    <x-jr.card>
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[240px]">
                <x-jr.input label="Buscar" icon="search" wire:model.live.debounce.500ms="search" placeholder="Nome ou CPF/CNPJ..." />
            </div>
            <div class="w-48">
                <label class="mb-2 block text-sm font-medium text-mono-600">Status</label>
                <select wire:model.live="status" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-colors focus:border-primary-500 focus:ring-0">
                    <option value="">Todos</option>
                    <option value="1">Ativos</option>
                    <option value="0">Inativos</option>
                </select>
            </div>
            <div class="w-48">
                <label class="mb-2 block text-sm font-medium text-mono-600">Tipo</label>
                <select wire:model.live="filterType" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-colors focus:border-primary-500 focus:ring-0">
                    <option value="">Todos</option>
                    <option value="cedente">Cedente</option>
                    <option value="advogado">Advogado</option>
                    <option value="corretor">Corretor</option>
                </select>
            </div>
            
            <x-jr.button type="button" class="shrink-0" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Novo contato
            </x-jr.button>
        </div>
    </x-jr.card>

    <x-jr.card :padding="false">
        @if ($contacts->isEmpty())
            <div class="py-10 text-center text-sm text-mono-600">Nenhum contato encontrado.</div>
        @else
            <x-jr.table>
                <x-slot:head>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-mono-600">Nome</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-mono-600">Tipo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-mono-600">Documento</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-mono-600">Telefone</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-mono-600">E-mail</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-mono-600">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-mono-600">Ações</th>
                </x-slot>

                @foreach ($contacts as $contact)
                    <tr class="transition-colors hover:bg-mono-50">
                        <td class="px-4 py-4 text-sm font-medium text-mono-900">
                            <a href="{{ route('contacts.show', $contact) }}" class="transition-colors hover:text-primary-500">{{ $contact->name }}</a>
                        </td>
                        <td class="px-4 py-4 text-sm text-mono-600">{{ $contact->typeLabel() }}</td>
                        <td class="px-4 py-4 text-sm text-mono-600">{{ $contact->document ?: '—' }}</td>
                        <td class="px-4 py-4 text-sm text-mono-600">{{ $contact->phone ?: '—' }}</td>
                        <td class="px-4 py-4 text-sm text-mono-600">{{ $contact->email ?: '—' }}</td>
                        <td class="px-4 py-4 text-center">
                            <x-jr.badge :variant="$contact->status ? 'success' : 'neutral'" size="sm">{{ $contact->status ? 'Ativo' : 'Inativo' }}</x-jr.badge>
                        </td>
                        <td class="px-4 py-4 text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded-lg p-1.5 text-mono-400 transition-colors hover:bg-primary-100 hover:text-primary-500" wire:click="edit({{ $contact->id }})" title="Editar">
                                    <span class="material-icons-outlined text-[18px]">edit</span>
                                </button>
                                <a href="{{ route('contacts.show', $contact) }}" class="rounded-lg p-1.5 text-mono-400 transition-colors hover:bg-mono-100 hover:text-mono-900" title="Ver detalhes">
                                    <span class="material-icons-outlined text-[18px]">visibility</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-jr.table>
            <div class="p-6">{{ $contacts->links() }}</div>
        @endif
    </x-jr.card>

    {{-- MODAL DE CADASTRO/EDIÇÃO --}}
    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Contato' : 'Novo Contato' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                        
                        <section>
                            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                <span class="material-icons-outlined text-[20px] text-primary-500">person</span>
                                <h4 class="text-base font-bold text-mono-900">Dados Pessoais</h4>
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                                    <select wire:model="type_form" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
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
                                <h4 class="text-base font-bold text-mono-900">Contato</h4>
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <x-jr.input label="Telefone" icon="phone" name="phone" wire:model="phone" x-phone />
                                <x-jr.input label="E-mail" icon="mail" name="email" wire:model="email" type="email" />
                            </div>
                        </section>

                        <section>
                            <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                <span class="material-icons-outlined text-[20px] text-primary-500">location_on</span>
                                <h4 class="text-base font-bold text-mono-900">Endereço</h4>
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
                                <h4 class="text-base font-bold text-mono-900">Dados Bancários</h4>
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
                                <h4 class="text-base font-bold text-mono-900">Outros</h4>
                            </div>
                            <div class="flex flex-col gap-4">
                                <label class="flex w-fit cursor-pointer items-center gap-3 text-sm">
                                    <input type="checkbox" wire:model="status_form" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                                    <span class="font-medium text-mono-900">Contato ativo</span>
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
                            {{ $editingId ? 'Salvar Contato' : 'Criar Contato' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

<?php

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\IdentityChangeRequest;
use App\Domains\Contacts\Services\PersonIdentityService;
use App\Support\BrazilianDocuments;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

/**
 * Meus dados: a própria pessoa edita o cadastro dela (o mesmo de Contatos).
 * Nome, celular e nascimento valem na hora (e em todos os módulos). CPF já cadastrado e exclusão de e-mail vão para aprovação do admin.
 */
new class extends Component {
    public string $name = '';

    public string $phone = '';

    public ?string $birth_date = null;

    public string $zip_code = '';

    public string $street = '';

    public string $number = '';

    public string $complement = '';

    public string $neighborhood = '';

    public string $city = '';

    public string $state = '';

    public string $pix_key_type = '';

    public string $pix_key = '';

    public string $addressMessage = '';

    public string $newDocument = '';

    public string $newEmail = '';

    public string $message = '';

    public function mount(): void
    {
        $contact = $this->contact();
        if ($contact) {
            $this->name = (string) $contact->name;
            $this->phone = (string) $contact->phone;
            $this->birth_date = $contact->birth_date?->toDateString();
            foreach (['zip_code', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'pix_key_type', 'pix_key'] as $f) {
                $this->{$f} = (string) ($contact->{$f} ?? '');
            }
        }
    }

    private function contact(): ?Contact
    {
        $id = auth()->user()?->contact_id;

        return $id ? Contact::withTests()->find($id) : null;
    }

    private function mine(): Contact
    {
        $contact = $this->contact();
        abort_unless($contact, 404);

        return $contact;
    }

    public function saveData(): void
    {
        $contact = $this->mine();
        $this->message = '';
        $data = $this->validate([
            'name' => 'required|string|min:2|max:200',
            'phone' => ['nullable', 'string', 'max:30', function ($a, $v, $fail) {
                if (trim((string) $v) !== '' && ! BrazilianDocuments::isValidPhone($v)) {
                    $fail('Informe um telefone válido com DDD (10 ou 11 dígitos).');
                }
            }],
            'birth_date' => 'nullable|date|before:today|after:1900-01-01',
        ], ['name.required' => 'Informe seu nome.', 'birth_date.before' => 'Data inválida.']);
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $phone = trim((string) $data['phone']) !== '' ? BrazilianDocuments::formatPhone($data['phone']) : null;
        $phones = array_values(array_unique(array_filter(array_merge([$phone], (array) $contact->phones))));
        $contact->update(['name' => $name, 'phone' => $phone ?? ($phones[0] ?? null), 'phones' => $phones, 'birth_date' => $data['birth_date'] ?: null]);
        $this->name = $name;
        $this->message = 'Dados salvos. O nome já vale em todo o sistema.';
    }

    /** Endereço e Pix: valem direto (sem aprovação) e ficam no log de auditoria pelo próprio Contato. */
    public function saveAddress(): void
    {
        $contact = $this->mine();
        $this->addressMessage = '';
        $data = $this->validate([
            'zip_code' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => trim((string) $v) === '' || strlen(BrazilianDocuments::digits($v)) === 8 ? null : $fail('CEP com 8 números.')],
            'street' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:30',
            'complement' => 'nullable|string|max:255',
            'neighborhood' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'state' => ['nullable', 'string', 'max:2', fn ($a, $v, $fail) => trim((string) $v) === '' || preg_match('/^[A-Za-z]{2}$/', trim($v)) ? null : $fail('Use a sigla (ex.: CE).')],
            'pix_key_type' => 'nullable|in:email,cpf,telefone,aleatoria',
            'pix_key' => ['nullable', 'string', 'max:200', function ($a, $v, $fail) {
                $v = trim((string) $v);
                if ($v === '') {
                    return;
                }
                $ok = match ($this->pix_key_type) {
                    'email' => (bool) filter_var($v, FILTER_VALIDATE_EMAIL),
                    'cpf' => BrazilianDocuments::isValidCpfOrCnpj($v),
                    'telefone' => (bool) preg_match('/^\d{10,13}$/', BrazilianDocuments::digits($v)),
                    'aleatoria' => mb_strlen($v) >= 8,
                    default => false,
                };
                if (! $ok) {
                    $fail($this->pix_key_type === '' ? 'Escolha o tipo da chave Pix.' : 'Chave Pix não confere com o tipo escolhido.');
                }
            }],
        ], [], ['zip_code' => 'CEP', 'street' => 'rua', 'state' => 'UF']);
        $digits = BrazilianDocuments::digits($data['zip_code']);
        $fields = [
            'zip_code' => $digits !== '' ? substr($digits, 0, 5).'-'.substr($digits, 5) : null,
            'street' => trim((string) $data['street']) ?: null,
            'number' => trim((string) $data['number']) ?: null,
            'complement' => trim((string) $data['complement']) ?: null,
            'neighborhood' => trim((string) $data['neighborhood']) ?: null,
            'city' => trim((string) $data['city']) ?: null,
            'state' => trim((string) $data['state']) !== '' ? mb_strtoupper(trim($data['state'])) : null,
            'pix_key_type' => ($data['pix_key_type'] ?? '') ?: null,
            'pix_key' => trim((string) $data['pix_key']) ?: null,
        ];
        $line = trim(collect([$fields['street'], $fields['number']])->filter()->implode(', '));
        $details = trim(collect([$fields['neighborhood'], $fields['complement'], $fields['city'], $fields['state'], $fields['zip_code']])->filter()->implode(' - '));
        $fields['address'] = collect([$line, $details])->filter()->implode(' | ') ?: null;
        $contact->update($fields);
        foreach (['zip_code', 'state'] as $f) {
            $this->{$f} = (string) ($fields[$f] ?? '');
        }
        $this->addressMessage = 'Endereço e Pix salvos.';
    }

    public function requestDocument(PersonIdentityService $service): void
    {
        $contact = $this->mine();
        $this->message = '';
        $this->resetErrorBag('newDocument');
        if (! BrazilianDocuments::isValidCpfOrCnpj($this->newDocument)) {
            $this->addError('newDocument', 'Informe um CPF válido.');

            return;
        }
        try {
            if (BrazilianDocuments::digits($contact->document) === '') {
                // Primeiro cadastro do CPF: vale direto (só a troca depois precisa de aprovação).
                if ($service->documentOwner($this->newDocument, $contact->id)) {
                    throw ValidationException::withMessages(['document' => 'Este CPF já está em outro cadastro. Fale com o administrador.']);
                }
                $contact->update(['document' => BrazilianDocuments::formatCpfCnpj($this->newDocument)]);
                $this->message = 'CPF cadastrado.';
            } else {
                $service->requestDocumentChange($contact, $this->newDocument, auth()->user());
                $this->message = 'Pedido de troca do CPF enviado. Até o administrador aprovar, vale o CPF atual.';
            }
            $this->newDocument = '';
        } catch (ValidationException $e) {
            $this->addError('newDocument', collect($e->errors())->flatten()->first());
        }
    }

    public function addEmail(PersonIdentityService $service): void
    {
        $contact = $this->mine();
        $this->message = '';
        $this->resetErrorBag('newEmail');
        try {
            $service->addEmail($contact, $this->newEmail);
            $this->message = 'E-mail adicionado.';
            $this->newEmail = '';
        } catch (ValidationException $e) {
            $this->addError('newEmail', collect($e->errors())->flatten()->first());
        }
    }

    public function removeEmail(string $email, PersonIdentityService $service): void
    {
        $contact = $this->mine();
        $this->message = '';
        $this->resetErrorBag('emails');
        try {
            $service->requestEmailRemoval($contact, $email, auth()->user());
            $this->message = 'Pedido de exclusão do e-mail enviado. Ele continua no cadastro até o administrador aprovar.';
        } catch (ValidationException $e) {
            $this->addError('emails', collect($e->errors())->flatten()->first());
        }
    }

    public function cancelRequest(int $id, PersonIdentityService $service): void
    {
        $contact = $this->mine();
        $req = IdentityChangeRequest::where('contact_id', $contact->id)->findOrFail($id);
        try {
            $service->cancel($req, auth()->user());
            $this->message = 'Pedido cancelado.';
        } catch (ValidationException $e) {
            $this->message = collect($e->errors())->flatten()->first();
        }
    }

    public function with(PersonIdentityService $service): array
    {
        $contact = $this->contact();

        return [
            'contact' => $contact,
            'emails' => $contact ? $service->emailsOf($contact) : [],
            'loginEmails' => $contact ? $service->loginEmailsOf($contact) : [],
            'pending' => $contact ? $contact->identityRequests()->pending()->orderBy('id')->get() : collect(),
            'recent' => $contact ? $contact->identityRequests()->whereIn('status', ['approved', 'rejected'])->where('decided_at', '>=', now()->subDays(30))->orderByDesc('id')->limit(5)->get() : collect(),
        ];
    }
}; ?>

<section class="flex flex-col gap-5" data-my-identity>
    <header>
        <h2 class="text-lg font-bold text-mono-900">Meus dados</h2>
        <p class="mt-1 text-sm text-mono-600">É o seu cadastro no sistema. O nome que você salvar aqui aparece igual em todos os lugares.</p>
    </header>

    @if (! $contact)
        <p class="text-sm text-mono-600">Seu cadastro ainda não está ligado a um contato. Fale com o administrador.</p>
    @else
        @if ($message)<x-jr.alert variant="success">{{ $message }}</x-jr.alert>@endif

        <form wire:submit="saveData" class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2"><x-jr.input label="Nome completo" name="name" id="my-name" wire:model="name" autocomplete="name" /></div>
            <x-jr.input label="Celular" name="phone" id="my-phone" type="tel" x-phone placeholder="(99) 99999-9999" maxlength="15" wire:model="phone" />
            <x-jr.input label="Data de nascimento" name="birth_date" id="my-birth" type="date" wire:model="birth_date" />
            <div class="md:col-span-2"><x-jr.button type="submit" class="w-full md:w-auto" data-save-my-data>Salvar meus dados</x-jr.button></div>
        </form>

        {{-- Endereço e Pix. O CEP é buscado pelo navegador (ViaCEP); se não achar, preenche à mão. --}}
        <form wire:submit="saveAddress" class="border-t border-mono-100 pt-5" data-my-address
            x-data="{
                cepStatus: '',
                async lookup() {
                    const d = ($wire.zip_code || '').replace(/\D/g, '');
                    if (d.length !== 8) { this.cepStatus = ''; return; }
                    this.cepStatus = 'buscando';
                    try {
                        const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 5000);
                        const r = await fetch('https://viacep.com.br/ws/' + d + '/json/', { signal: ctl.signal }); clearTimeout(t);
                        const j = await r.json();
                        if (!r.ok || j.erro) throw new Error('cep');
                        $wire.street = j.logradouro || $wire.street; $wire.neighborhood = j.bairro || $wire.neighborhood;
                        $wire.city = j.localidade || $wire.city; $wire.state = j.uf || $wire.state;
                        if (j.complemento && !$wire.complement) $wire.complement = j.complemento;
                        this.cepStatus = 'ok';
                    } catch (e) { this.cepStatus = 'falhou'; }
                },
                maskCep(el) { let d = el.value.replace(/\D/g, '').slice(0, 8); el.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d; $wire.zip_code = el.value; }
            }">
            <h3 class="font-bold text-mono-900">Endereço e Pix</h3>
            <p class="mt-1 text-sm text-mono-600">Valem na hora, sem aprovação.</p>
            @if ($addressMessage)<x-jr.alert variant="success" class="mt-3">{{ $addressMessage }}</x-jr.alert>@endif
            <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <x-jr.input label="CEP" name="zip_code" id="my-zip" inputmode="numeric" maxlength="9" placeholder="00000-000" wire:model="zip_code" x-on:input="maskCep($event.target)" x-on:blur="lookup()" x-on:keyup="($event.target.value.replace(/\D/g, '').length === 8 && cepStatus === '') && lookup()" />
                    <p class="mt-1 text-xs text-mono-600" x-show="cepStatus === 'buscando'" x-cloak>Buscando o endereço…</p>
                    <p class="mt-1 text-xs text-success" x-show="cepStatus === 'ok'" x-cloak data-cep-ok>Endereço preenchido pelo CEP. Confira o número.</p>
                    <p class="mt-1 text-xs text-mono-600" x-show="cepStatus === 'falhou'" x-cloak data-cep-fail>Não achamos o CEP agora. Preencha o endereço à mão.</p>
                </div>
                <x-jr.input label="Rua" name="street" id="my-street" wire:model="street" autocomplete="address-line1" />
                <x-jr.input label="Número" name="number" id="my-number" wire:model="number" />
                <x-jr.input label="Complemento" name="complement" id="my-complement" wire:model="complement" />
                <x-jr.input label="Bairro" name="neighborhood" id="my-neighborhood" wire:model="neighborhood" />
                <x-jr.input label="Cidade" name="city" id="my-city" wire:model="city" autocomplete="address-level2" />
                <x-jr.input label="UF" name="state" id="my-state" maxlength="2" placeholder="CE" wire:model="state" x-on:input="$event.target.value = $event.target.value.toUpperCase()" />
                <div>
                    <label for="my-pix-type" class="mb-2 block text-sm font-medium text-mono-600">Tipo de chave Pix</label>
                    <select id="my-pix-type" wire:model="pix_key_type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900"><option value="">Nenhuma</option><option value="cpf">CPF/CNPJ</option><option value="email">E-mail</option><option value="telefone">Telefone</option><option value="aleatoria">Aleatória</option></select>
                    @error('pix_key_type')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                </div>
                <x-jr.input label="Chave Pix" name="pix_key" id="my-pix" wire:model="pix_key" autocomplete="off" />
                <div class="md:col-span-2"><x-jr.button type="submit" class="w-full md:w-auto" data-save-address>Salvar endereço e Pix</x-jr.button></div>
            </div>
        </form>

        <div class="border-t border-mono-100 pt-5" data-my-document>
            <h3 class="font-bold text-mono-900">CPF</h3>
            <p class="mt-1 text-sm text-mono-600">CPF atual: <strong class="text-mono-900">{{ $contact->document ?: 'não cadastrado' }}</strong></p>
            @php $docReq = $pending->firstWhere('type', 'document'); @endphp
            @if ($docReq)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-info-bg px-4 py-3 text-sm text-info" data-pending-document>
                    <span>Troca para <strong>{{ $docReq->new_value }}</strong> aguardando aprovação do administrador.</span>
                    <button type="button" wire:click="cancelRequest({{ $docReq->id }})" class="font-semibold underline">Cancelar pedido</button>
                </div>
            @endif
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="flex-1"><x-jr.input :label="$contact->document ? 'Novo CPF (vai para aprovação)' : 'Cadastrar CPF'" name="newDocument" id="my-new-document" x-cpf-cnpj inputmode="numeric" placeholder="000.000.000-00" wire:model="newDocument" /></div>
                <x-jr.button variant="standard" class="sm:mt-7" wire:click="requestDocument" data-request-document>{{ $contact->document ? 'Pedir troca do CPF' : 'Salvar CPF' }}</x-jr.button>
            </div>
        </div>

        <div class="border-t border-mono-100 pt-5" data-my-emails>
            <h3 class="font-bold text-mono-900">E-mails</h3>
            <p class="mt-1 text-sm text-mono-600">Você pode adicionar outro e-mail quando quiser. Para excluir um e-mail, o pedido vai para aprovação.</p>
            @error('emails')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
            <ul class="mt-3 flex flex-col gap-2">
                @foreach ($emails as $email)
                    @php $rm = $pending->first(fn ($r) => $r->type === 'email_remove' && $r->old_value === $email); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-mono-100 px-4 py-3 text-sm" wire:key="my-email-{{ md5($email) }}" data-email-row="{{ $email }}">
                        <span class="min-w-0 break-all text-mono-900">{{ $email }}
                            @if (in_array($email, $loginEmails, true))<x-jr.badge size="sm" variant="info">acesso</x-jr.badge>@endif
                        </span>
                        @if ($rm)
                            <span class="flex items-center gap-2 text-xs text-info">Exclusão aguardando aprovação <button type="button" wire:click="cancelRequest({{ $rm->id }})" class="font-semibold underline">Cancelar</button></span>
                        @elseif (! in_array($email, $loginEmails, true))
                            <button type="button" wire:click="removeEmail(@js($email))" wire:confirm="Pedir a exclusão deste e-mail? Ele continua até o administrador aprovar." class="text-xs font-semibold text-error" data-remove-email>Pedir exclusão</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="flex-1"><x-jr.input label="Adicionar e-mail" name="newEmail" id="my-new-email" type="email" inputmode="email" placeholder="nome@exemplo.com" wire:model="newEmail" /></div>
                <x-jr.button variant="standard" class="sm:mt-7" wire:click="addEmail" data-add-email>Adicionar</x-jr.button>
            </div>
        </div>

        @if ($recent->isNotEmpty())
            <div class="border-t border-mono-100 pt-5 text-sm">
                <h3 class="font-bold text-mono-900">Pedidos decididos (últimos 30 dias)</h3>
                <ul class="mt-2 flex flex-col gap-1 text-mono-600">
                    @foreach ($recent as $r)
                        <li>{{ $r->typeLabel() }} ({{ $r->type === 'document' ? $r->new_value : $r->old_value }}): <strong class="{{ $r->status === 'approved' ? 'text-success' : 'text-error' }}">{{ $r->statusLabel() }}</strong>{{ $r->decision_note ? ' · '.$r->decision_note : '' }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</section>

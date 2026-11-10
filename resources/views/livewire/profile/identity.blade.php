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

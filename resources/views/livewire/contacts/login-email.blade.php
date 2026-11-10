<?php

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\PersonIdentityService;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

/** E-mail de acesso (login) da pessoa ligada a este contato. Só o admin troca, com confirmação. */
new class extends Component {
    public int $contactId;

    public ?int $editingUserId = null;

    public string $newLoginEmail = '';

    public bool $confirming = false;

    public string $message = '';

    public function mount(Contact $contact): void
    {
        $this->contactId = $contact->id;
    }

    public function start(int $userId): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->editingUserId = User::where('contact_id', $this->contactId)->findOrFail($userId)->id;
        $this->newLoginEmail = '';
        $this->confirming = false;
        $this->message = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('editingUserId', 'newLoginEmail', 'confirming');
        $this->resetErrorBag();
    }

    /** 1º passo: confere o e-mail e mostra a confirmação. */
    public function review(PersonIdentityService $service): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->resetErrorBag();
        $user = User::where('contact_id', $this->contactId)->findOrFail($this->editingUserId);
        $new = PersonIdentityService::normEmail($this->newLoginEmail);
        $this->newLoginEmail = $new;
        if (! filter_var($new, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newLoginEmail', 'Informe um e-mail válido.');

            return;
        }
        if ($new === PersonIdentityService::normEmail($user->email)) {
            $this->addError('newLoginEmail', 'Este já é o e-mail de acesso.');

            return;
        }
        if (User::whereRaw('lower(email) = ?', [$new])->whereKeyNot($user->id)->exists()) {
            $this->addError('newLoginEmail', 'Este e-mail já é o acesso de outro usuário.');

            return;
        }
        if ($owner = $service->emailOwnerName($new, $this->contactId)) {
            $this->addError('newLoginEmail', 'Este e-mail pertence a '.$owner.'.');

            return;
        }
        $this->confirming = true;
    }

    /** 2º passo: grava (sem enviar e-mail). */
    public function confirm(PersonIdentityService $service): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $user = User::where('contact_id', $this->contactId)->findOrFail($this->editingUserId);
        try {
            $service->changeLoginEmail($user, $this->newLoginEmail, auth()->user());
        } catch (ValidationException $e) {
            $this->confirming = false;
            $this->addError('newLoginEmail', collect($e->errors())->flatten()->first());

            return;
        }
        $this->message = 'E-mail de acesso trocado para '.$this->newLoginEmail.'. O anterior continua na lista de e-mails do contato. Nenhum e-mail foi enviado.';
        $this->reset('editingUserId', 'newLoginEmail', 'confirming');
    }

    public function with(): array
    {
        return ['users' => auth()->user()?->isAdmin() ? User::where('contact_id', $this->contactId)->orderBy('id')->get() : collect()];
    }
}; ?>

<div id="acesso" data-login-email>
    @if ($users->isNotEmpty())
        <x-jr.card>
            <h3 class="text-lg font-bold text-mono-900">Acesso ao sistema</h3>
            <p class="mt-1 text-sm text-mono-600">E-mail com que a pessoa entra no sistema (senha ou Google). Só o administrador troca.</p>
            @if ($message)<x-jr.alert variant="success" class="mt-3">{{ $message }}</x-jr.alert>@endif
            <ul class="mt-3 flex flex-col gap-3">
                @foreach ($users as $u)
                    <li class="rounded-xl border border-mono-100 px-4 py-3" wire:key="login-{{ $u->id }}" data-login-user="{{ $u->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="break-all font-semibold text-mono-900" data-current-login>{{ $u->email }}</p>
                                <p class="text-xs text-mono-600">{{ $u->is_admin ? 'Administrador' : 'Usuário' }} · {{ $u->google_sub ? 'entra também pelo Google' : 'Google ainda não usado' }}</p>
                            </div>
                            @if ($editingUserId !== $u->id)
                                <x-jr.button variant="standard" size="sm" wire:click="start({{ $u->id }})" data-change-login>Trocar e-mail de acesso</x-jr.button>
                            @endif
                        </div>
                        @if ($editingUserId === $u->id)
                            <div class="mt-3 flex flex-col gap-3">
                                @if (! $confirming)
                                    <x-jr.input label="Novo e-mail de acesso" name="newLoginEmail" id="new-login-email" type="email" inputmode="email" autocomplete="off" placeholder="nome@exemplo.com" wire:model="newLoginEmail" />
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-jr.button variant="mono" wire:click="cancel">Cancelar</x-jr.button>
                                        <x-jr.button wire:click="review" data-review-login>Continuar</x-jr.button>
                                    </div>
                                @else
                                    <div class="rounded-xl bg-info-bg px-4 py-3 text-sm text-info" data-confirm-box>
                                        <p class="font-semibold">Confirmar a troca?</p>
                                        <p class="mt-1 break-all">De <strong>{{ $u->email }}</strong> para <strong>{{ $newLoginEmail }}</strong>.</p>
                                        <p class="mt-1">A pessoa passa a entrar com o novo e-mail (a senha continua a mesma). Se o novo for uma conta Google, o botão "Entrar com Google" passa a usar essa conta. O e-mail antigo continua na lista do contato. Nenhum e-mail será enviado.</p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-jr.button variant="mono" wire:click="$set('confirming', false)">Voltar</x-jr.button>
                                        <x-jr.button wire:click="confirm" data-confirm-login>Confirmar troca</x-jr.button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-jr.card>
    @endif
</div>

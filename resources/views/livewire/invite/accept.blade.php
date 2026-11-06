<?php

use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    #[Locked]
    public string $token = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function accept(): void
    {
        $this->validate(['password' => 'required|string|min:12|max:200|confirmed'], [
            'password.min' => 'A senha precisa ter pelo menos 12 caracteres.',
            'password.confirmed' => 'A confirmação não confere.',
        ]);
        UserAccessService::accept($this->token, $this->password);
        session()->flash('status', 'Conta criada. Entre com o e-mail do convite e a senha que você definiu.');
        $this->redirectRoute('login');
    }

    public function with(): array
    {
        return ['invitation' => UserInvitation::findUsable($this->token)];
    }
}; ?>

<div data-invite-accept>
    @if (! $invitation)
        <h2 class="text-xl font-bold text-mono-900">Convite inválido</h2>
        <p class="mt-2 text-sm text-mono-600">Este link expirou, foi revogado, já foi usado ou não existe. Peça um novo convite ao administrador.</p>
    @else
        <h2 class="text-xl font-bold text-mono-900">Criar sua conta</h2>
        <p class="mt-2 text-sm text-mono-600">Olá, {{ $invitation->name }}. Defina sua senha para entrar.</p>
        <form wire:submit="accept" class="mt-6 space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-mono-600" for="invite-email">E-mail (não pode ser alterado)</label>
                <input id="invite-email" type="email" value="{{ $invitation->email }}" readonly disabled class="h-12 w-full rounded-pill border border-mono-200 bg-mono-50 px-4 text-sm text-mono-700">
            </div>
            <x-jr.input label="Senha (mínimo 12 caracteres) *" name="password" type="password" wire:model="password" autocomplete="new-password" />
            <x-jr.input label="Confirmar senha *" name="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" />
            @error('password')<p class="text-xs text-error">{{ $message }}</p>@enderror
            <button type="submit" class="h-12 w-full rounded-pill bg-primary-500 text-sm font-semibold text-white">Criar conta</button>
        </form>
    @endif
</div>

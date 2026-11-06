<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: \App\Support\UserPreferences::homeUrl(auth()->user()), navigate: true);
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-8 mt-2 text-center">
        <h2 class="text-xl font-bold text-mono-900">Entrar no Sistema</h2>
    </div>

    <form wire:submit="login" class="space-y-6">
        <!-- Email Address -->
        <x-jr.input 
            label="E-mail" 
            icon="mail" 
            wire:model="form.email" 
            id="email" 
            type="email" 
            name="email" 
            required autofocus autocomplete="username" 
            placeholder="seu@email.com" 
        />

        <!-- Password -->
        <x-jr.input 
            label="Senha" 
            icon="key" 
            wire:model="form.password" 
            id="password" 
            type="password" 
            name="password" 
            required autocomplete="current-password" 
            placeholder="Sua senha" 
        />

        <!-- Remember Me -->
        <div class="flex items-center">
            <label for="remember" class="flex cursor-pointer items-center gap-2">
                <input wire:model="form.remember" id="remember" type="checkbox" class="h-4 w-4 rounded border-mono-200 text-primary-500 focus:ring-primary-500" name="remember">
                <span class="text-sm text-mono-500">Lembrar de mim</span>
            </label>
        </div>

        <x-jr.button type="submit" class="w-full">
            Entrar
        </x-jr.button>
    </form>

    @php
        $googleLogin = app(\App\Support\GoogleAuth\IdentityProvider::class)->configured();
    @endphp
    @if ($googleLogin)
        <div class="mt-6">
            <div class="mb-4 flex items-center gap-3 text-xs text-mono-400">
                <span class="h-px flex-1 bg-mono-200"></span>ou<span class="h-px flex-1 bg-mono-200"></span>
            </div>
            <a href="{{ route('login.google') }}" class="flex w-full items-center justify-center gap-3 rounded-lg border border-mono-200 bg-white px-4 py-2.5 text-sm font-medium text-mono-700 hover:bg-mono-50">
                <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                Continuar com Google
            </a>
        </div>
    @endif
</div>

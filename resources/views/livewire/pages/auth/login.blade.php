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

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
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
</div>

<?php

use App\Support\UserPreferences;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $theme = 'light';

    public string $accent = 'laranja';

    public string $text_size = 'padrao';

    public string $density = 'confortavel';

    public string $home = 'dashboard';

    public ?string $message = null;

    public function with(): array
    {
        return ['logoUrl' => \App\Support\BrandLogo::url(auth()->user())];
    }

    public function mount(): void
    {
        $this->fill(UserPreferences::for(auth()->user()));
    }

    public function save(): void
    {
        $data = $this->validate(UserPreferences::rules());
        auth()->user()->forceFill(['preferences' => $data])->save();
        $this->redirectRoute('settings', ['salvo' => 1]);
    }

    public function cancel(): void
    {
        $this->fill(UserPreferences::for(auth()->user()));
        $this->resetValidation();
        $this->message = 'Alterações descartadas.';
        $this->dispatch('prefs-preview', accent: $this->accent);
    }

    public function restoreDefaults(): void
    {
        $this->fill(UserPreferences::DEFAULTS);
        $this->message = 'Padrões carregados no formulário. Clique em Salvar para aplicar.';
        $this->dispatch('prefs-preview', accent: $this->accent);
    }
}; ?>

<x-slot name="header">Configurações</x-slot>

<div class="mx-auto max-w-3xl space-y-6" data-settings-page>
    <div>
        <h2 class="text-2xl font-bold tracking-tight">Configurações</h2>
        <p class="mt-2 text-sm text-mono-600">Preferências de aparência só da sua conta. Não mudam dados, permissões nem o que outros usuários veem.</p>
    </div>

    @if (request('salvo'))<x-jr.alert variant="success">Preferências salvas e aplicadas.</x-jr.alert>@endif
    @if ($message)<x-jr.alert variant="info">{{ $message }}</x-jr.alert>@endif

    @if (auth()->user()?->isAdmin())
        <x-jr.card data-settings-users>
            <h3 class="font-bold">Usuários e acessos</h3>
            <p class="mb-3 text-sm text-mono-600">Convidar pessoas por e-mail e escolher os módulos de cada uma.</p>
            <a href="{{ route('settings.users') }}" class="text-sm font-semibold text-primary-600">Abrir usuários e acessos →</a>
        </x-jr.card>
    @endif

    @include('livewire.settings.logo-section')

    <form wire:submit="save" class="space-y-6">
        <x-jr.card>
            <h3 class="font-bold">Tema</h3>
            <p class="mb-3 text-sm text-mono-600">Automático acompanha o modo claro/escuro do celular ou computador. O botão de lua no topo troca só nesta aba, até fechar o navegador.</p>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (UserPreferences::THEMES as $k => $l)
                    <label class="flex cursor-pointer items-center gap-2 rounded-sm border border-mono-200 p-3 text-sm"><input type="radio" wire:model="theme" value="{{ $k }}"> {{ $l }}</label>
                @endforeach
            </div>
            @error('theme')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
        </x-jr.card>

        <x-jr.card>
            <h3 class="font-bold">Cor de destaque</h3>
            <p class="mb-3 text-sm text-mono-600">Usada em botões, abas ativas e links. A prévia abaixo muda na hora; só fica gravada ao salvar.</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3" x-data @prefs-preview.window="window.previewAccent($event.detail.accent)">
                @foreach (UserPreferences::ACCENTS as $k => [$l, $light, $dark])
                    <label class="flex cursor-pointer items-center gap-2 rounded-sm border border-mono-200 p-3 text-sm">
                        <input type="radio" wire:model="accent" value="{{ $k }}" x-on:change="window.previewAccent('{{ $k }}')">
                        <span class="inline-block h-5 w-5 rounded-full" style="background: {{ $light[1] }}"></span>{{ $l }}
                    </label>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-sm" data-accent-preview>
                <span class="rounded-sm bg-primary-500 px-3 py-1.5 font-semibold text-white">Botão</span>
                <span class="border-b-2 border-primary-500 pb-1 font-semibold text-primary-500">Aba ativa</span>
                <span class="rounded-full bg-primary-100 px-3 py-1 text-primary-500">Etiqueta</span>
            </div>
            @error('accent')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
        </x-jr.card>

        <x-jr.card>
            <h3 class="font-bold">Tamanho do texto e densidade</h3>
            <p class="mb-3 text-sm text-mono-600">Texto maior amplia toda a interface. Compacta reduz espaços em tabelas e margens para caber mais informação.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col gap-1 text-sm">Tamanho do texto
                    <select wire:model="text_size" class="h-10 rounded-sm border border-mono-200 bg-mono-white px-2">@foreach (UserPreferences::TEXT_SIZES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    @error('text_size')<span class="text-xs text-error">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1 text-sm">Densidade
                    <select wire:model="density" class="h-10 rounded-sm border border-mono-200 bg-mono-white px-2">@foreach (UserPreferences::DENSITIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    @error('density')<span class="text-xs text-error">{{ $message }}</span>@enderror
                </label>
            </div>
        </x-jr.card>

        <x-jr.card>
            <h3 class="font-bold">Área inicial</h3>
            <p class="mb-3 text-sm text-mono-600">Tela aberta depois de entrar e ao acessar o endereço principal do app.</p>
            <select wire:model="home" class="h-10 w-full rounded-sm border border-mono-200 bg-mono-white px-2 text-sm">@foreach (UserPreferences::HOMES as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            @error('home')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
        </x-jr.card>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-sm bg-primary-500 px-4 py-2 text-sm font-semibold text-white">Salvar</button>
            <button type="button" wire:click="cancel" class="rounded-sm border border-mono-200 px-4 py-2 text-sm">Cancelar alterações</button>
            <button type="button" wire:click="restoreDefaults" class="ml-auto rounded-sm border border-mono-200 px-4 py-2 text-sm">Restaurar padrões</button>
        </div>
    </form>
</div>

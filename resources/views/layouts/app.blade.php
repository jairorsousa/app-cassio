<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    x-data="{ theme: localStorage.getItem('theme') || (document.cookie.match(/(?:^|;\s*)theme=(\w+)/)?.[1]) || 'light' }"
    x-init="$watch('theme', v => {
        localStorage.setItem('theme', v);
        document.cookie = 'theme=' + v + '; path=/; max-age=31536000; samesite=lax';
        document.documentElement.dataset.theme = v;
    })"
    :data-theme="theme"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cassio Finance') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        class="font-sans antialiased bg-mono-50 text-mono-900"
        x-data="appShortcuts({
            newIncome: '{{ route('banking.transactions.create', ['type' => 'income']) }}',
            newExpense: '{{ route('banking.transactions.create', ['type' => 'expense']) }}',
            newTransfer: '{{ route('banking.transactions.create', ['type' => 'transfer']) }}',
            newWrit: '{{ route('writs.create') }}',
        })"
    >
        <x-fx.toast />
        <div class="min-h-screen flex flex-col">

            {{-- Top navbar --}}
            <header class="bg-mono-white border-b border-mono-100 shrink-0">
                <div class="flex items-center h-16 px-lg gap-md">

                    {{-- Logo --}}
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-xs shrink-0 mr-sm">
                        <span class="inline-block w-8 h-8 rounded-full bg-primary-500"></span>
                        <span class="font-bold text-sm text-mono-900 whitespace-nowrap">Cassio Finance</span>
                    </a>

                    {{-- Nav links --}}
                    <nav class="flex-1 flex items-center gap-xxxs">

                        @php
                            $navItem = 'flex items-center gap-1 px-3 h-9 rounded-md text-xs font-medium transition-colors duration-150 whitespace-nowrap';
                            $navItemActive = 'bg-primary-100 text-primary-600 font-semibold';
                            $navItemInactive = 'text-mono-900 hover:bg-mono-100';
                            $dropdownItem = 'flex items-center px-3 h-9 rounded-md text-xs transition-colors duration-150 whitespace-nowrap';
                            $dropdownItemActive = 'bg-primary-100 text-primary-600 font-semibold';
                            $dropdownItemInactive = 'text-mono-900 hover:bg-mono-100';
                        @endphp

                        {{-- Dashboard --}}
                        <a href="{{ route('dashboard') }}"
                           class="{{ $navItem }} {{ request()->routeIs('dashboard') ? $navItemActive : $navItemInactive }}">
                            🏠 Dashboard
                        </a>

                        {{-- Financeiro --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="{{ route('banking.dashboard') }}"
                               class="{{ $navItem }} {{ request()->routeIs('banking.*') ? $navItemActive : $navItemInactive }}">
                                💰 Financeiro <span class="opacity-40 text-xxs">▾</span>
                            </a>
                            <div x-show="open" x-cloak
                                 class="absolute top-full left-0 mt-1 w-48 bg-mono-white border border-mono-100 rounded-md z-50 p-xs flex flex-col gap-xxxs"
                                 style="box-shadow: var(--shadow-dropdown)">
                                <a href="{{ route('banking.transactions.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.transactions.*') ? $dropdownItemActive : $dropdownItemInactive }}">Lançamentos</a>
                                <a href="{{ route('banking.accounts.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.accounts.*') ? $dropdownItemActive : $dropdownItemInactive }}">Contas</a>
                                <a href="{{ route('banking.cards.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.cards.*') ? $dropdownItemActive : $dropdownItemInactive }}">Cartões</a>
                                <a href="{{ route('banking.recurring.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.recurring.*') ? $dropdownItemActive : $dropdownItemInactive }}">Recorrências</a>
                                <a href="{{ route('banking.categories.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.categories.*') ? $dropdownItemActive : $dropdownItemInactive }}">Categorias</a>
                                <a href="{{ route('banking.reports.cashflow') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.reports.*') ? $dropdownItemActive : $dropdownItemInactive }}">Fluxo de caixa</a>
                            </div>
                        </div>

                        {{-- Corretores --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="{{ route('brokers.index') }}"
                               class="{{ $navItem }} {{ request()->routeIs('brokers.*') ? $navItemActive : $navItemInactive }}">
                                👥 Corretores <span class="opacity-40 text-xxs">▾</span>
                            </a>
                            <div x-show="open" x-cloak
                                 class="absolute top-full left-0 mt-1 w-44 bg-mono-white border border-mono-100 rounded-md z-50 p-xs flex flex-col gap-xxxs"
                                 style="box-shadow: var(--shadow-dropdown)">
                                <a href="{{ route('brokers.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('brokers.index') ? $dropdownItemActive : $dropdownItemInactive }}">Lista</a>
                                <a href="{{ route('brokers.create') }}" class="{{ $dropdownItem }} {{ request()->routeIs('brokers.create') ? $dropdownItemActive : $dropdownItemInactive }}">Novo</a>
                                <a href="{{ route('brokers.case-types.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('brokers.case-types.*') ? $dropdownItemActive : $dropdownItemInactive }}">Tipos de caso</a>
                                <a href="{{ route('brokers.reports') }}" class="{{ $dropdownItem }} {{ request()->routeIs('brokers.reports') ? $dropdownItemActive : $dropdownItemInactive }}">Relatórios</a>
                            </div>
                        </div>

                        {{-- Sociedade --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="{{ route('partnership.index') }}"
                               class="{{ $navItem }} {{ request()->routeIs('partnership.*') ? $navItemActive : $navItemInactive }}">
                                🏢 Sociedade <span class="opacity-40 text-xxs">▾</span>
                            </a>
                            <div x-show="open" x-cloak
                                 class="absolute top-full left-0 mt-1 w-40 bg-mono-white border border-mono-100 rounded-md z-50 p-xs flex flex-col gap-xxxs"
                                 style="box-shadow: var(--shadow-dropdown)">
                                <a href="{{ route('partnership.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('partnership.index') ? $dropdownItemActive : $dropdownItemInactive }}">Lista</a>
                                <a href="{{ route('partnership.create') }}" class="{{ $dropdownItem }} {{ request()->routeIs('partnership.create') ? $dropdownItemActive : $dropdownItemInactive }}">Nova</a>
                            </div>
                        </div>

                        {{-- Investimentos --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="{{ route('investments.dashboard') }}"
                               class="{{ $navItem }} {{ request()->routeIs('investments.*') ? $navItemActive : $navItemInactive }}">
                                📈 Investimentos <span class="opacity-40 text-xxs">▾</span>
                            </a>
                            <div x-show="open" x-cloak
                                 class="absolute top-full left-0 mt-1 w-44 bg-mono-white border border-mono-100 rounded-md z-50 p-xs flex flex-col gap-xxxs"
                                 style="box-shadow: var(--shadow-dropdown)">
                                <a href="{{ route('investments.dashboard') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.dashboard') ? $dropdownItemActive : $dropdownItemInactive }}">Visão geral</a>
                                <a href="{{ route('investments.positions') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.positions') ? $dropdownItemActive : $dropdownItemInactive }}">Posições</a>
                                <a href="{{ route('investments.operations.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.operations.*') ? $dropdownItemActive : $dropdownItemInactive }}">Operações</a>
                                <a href="{{ route('investments.dividends.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.dividends.*') ? $dropdownItemActive : $dropdownItemInactive }}">Proventos</a>
                                <a href="{{ route('investments.assets.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.assets.*') ? $dropdownItemActive : $dropdownItemInactive }}">Ativos</a>
                                <a href="{{ route('investments.reports') }}" class="{{ $dropdownItem }} {{ request()->routeIs('investments.reports') ? $dropdownItemActive : $dropdownItemInactive }}">Rentabilidade</a>
                            </div>
                        </div>

                        {{-- Requisitórios --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="{{ route('writs.kanban') }}"
                               class="{{ $navItem }} {{ request()->routeIs('writs.*') ? $navItemActive : $navItemInactive }}">
                                ⚖️ Requisitórios <span class="opacity-40 text-xxs">▾</span>
                            </a>
                            <div x-show="open" x-cloak
                                 class="absolute top-full left-0 mt-1 w-40 bg-mono-white border border-mono-100 rounded-md z-50 p-xs flex flex-col gap-xxxs"
                                 style="box-shadow: var(--shadow-dropdown)">
                                <a href="{{ route('writs.kanban') }}" class="{{ $dropdownItem }} {{ request()->routeIs('writs.kanban') ? $dropdownItemActive : $dropdownItemInactive }}">Pipeline</a>
                                <a href="{{ route('writs.create') }}" class="{{ $dropdownItem }} {{ request()->routeIs('writs.create') ? $dropdownItemActive : $dropdownItemInactive }}">Novo</a>
                                <a href="{{ route('writs.reports') }}" class="{{ $dropdownItem }} {{ request()->routeIs('writs.reports') ? $dropdownItemActive : $dropdownItemInactive }}">Relatórios</a>
                            </div>
                        </div>

                    </nav>

                    {{-- Right side --}}
                    <div class="flex items-center gap-xs shrink-0">

                        {{-- Theme toggle --}}
                        <button
                            type="button"
                            class="fx-btn fx-btn--icon"
                            @click="theme = theme === 'dark' ? 'light' : 'dark'"
                            aria-label="Alternar tema"
                        >
                            <span x-show="theme === 'light'">🌙</span>
                            <span x-show="theme === 'dark'" x-cloak>☀️</span>
                        </button>

                        {{-- Configurações --}}
                        <a href="{{ route('profile') }}"
                           class="fx-btn fx-btn--icon {{ request()->routeIs('profile') ? 'bg-primary-100' : '' }}"
                           title="Configurações">
                            ⚙️
                        </a>

                        {{-- User dropdown --}}
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button class="fx-btn fx-btn--standard fx-btn--sm">
                                    {{ auth()->user()->name ?? 'Conta' }}
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile')">
                                    {{ __('Profile') }}
                                </x-dropdown-link>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')"
                                        onclick="event.preventDefault(); this.closest('form').submit();">
                                        {{ __('Log Out') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>

                    </div>
                </div>
            </header>

            {{-- Page title bar --}}
            @if (isset($header))
                <div class="px-lg py-sm bg-mono-white border-b border-mono-100">
                    <h1 class="text-lg font-semibold text-mono-900">{{ $header }}</h1>
                </div>
            @endif

            {{-- Content --}}
            <main class="flex-1 p-lg">
                {{ $slot }}
            </main>

            {{-- Keyboard shortcuts footer --}}
            <footer class="px-lg py-xs text-xxs text-mono-600 border-t border-mono-100 bg-mono-white flex flex-wrap gap-md">
                <span><span class="fx-kbd">n</span> <span class="fx-kbd">r</span> nova receita</span>
                <span><span class="fx-kbd">n</span> <span class="fx-kbd">d</span> nova despesa</span>
                <span><span class="fx-kbd">n</span> <span class="fx-kbd">t</span> transferência</span>
                <span><span class="fx-kbd">n</span> <span class="fx-kbd">w</span> requisitório</span>
                <span><span class="fx-kbd">g</span> <span class="fx-kbd">d</span> dashboard</span>
            </footer>

        </div>

        <script>
            window.appShortcuts = (config) => ({
                buffer: '',
                bufferTimer: null,
                bindings: {
                    'nr': config.newIncome,
                    'nd': config.newExpense,
                    'nt': config.newTransfer,
                    'nw': config.newWrit,
                    'gd': '{{ route('dashboard') }}',
                },
                init() {
                    window.addEventListener('keydown', (e) => {
                        const tag = e.target?.tagName?.toLowerCase();
                        if (['input','textarea','select'].includes(tag) || e.target?.isContentEditable) return;
                        if (e.metaKey || e.ctrlKey || e.altKey) return;

                        const k = e.key.toLowerCase();
                        if (!/^[a-z]$/.test(k)) return;

                        this.buffer += k;
                        clearTimeout(this.bufferTimer);
                        this.bufferTimer = setTimeout(() => this.buffer = '', 800);

                        const target = this.bindings[this.buffer];
                        if (target) {
                            this.buffer = '';
                            window.location.href = target;
                        }
                    });
                }
            });
        </script>
    </body>
</html>

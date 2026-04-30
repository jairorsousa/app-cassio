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
                <div class="flex items-center h-16 px-lg gap-lg">

                    {{-- Logo --}}
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-xs shrink-0">
                        <span class="inline-block w-8 h-8 rounded-full bg-primary-500"></span>
                        <span class="font-bold text-md text-mono-900 whitespace-nowrap">Cassio Finance</span>
                    </a>

                    {{-- Nav links --}}
                    <nav class="flex-1 flex items-center gap-xxxs">

                        <x-fx.menu-item href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">
                            🏠 Dashboard
                        </x-fx.menu-item>

                        {{-- Financeiro --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <x-fx.menu-item href="{{ route('banking.dashboard') }}" :active="request()->routeIs('banking.*')">
                                💰 Financeiro
                                <svg class="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </x-fx.menu-item>
                            <div x-show="open" x-cloak class="absolute top-full left-0 mt-1 w-48 bg-mono-white border border-mono-100 rounded-md shadow-lg z-50 p-xs flex flex-col gap-xxxs">
                                <x-fx.menu-item href="{{ route('banking.transactions.index') }}" :active="request()->routeIs('banking.transactions.*')">Lançamentos</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('banking.accounts.index') }}" :active="request()->routeIs('banking.accounts.*')">Contas</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('banking.cards.index') }}" :active="request()->routeIs('banking.cards.*')">Cartões</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('banking.recurring.index') }}" :active="request()->routeIs('banking.recurring.*')">Recorrências</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('banking.categories.index') }}" :active="request()->routeIs('banking.categories.*')">Categorias</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('banking.reports.cashflow') }}" :active="request()->routeIs('banking.reports.*')">Fluxo de caixa</x-fx.menu-item>
                            </div>
                        </div>

                        {{-- Corretores --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <x-fx.menu-item href="{{ route('brokers.index') }}" :active="request()->routeIs('brokers.*')">
                                👥 Corretores
                                <svg class="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </x-fx.menu-item>
                            <div x-show="open" x-cloak class="absolute top-full left-0 mt-1 w-44 bg-mono-white border border-mono-100 rounded-md shadow-lg z-50 p-xs flex flex-col gap-xxxs">
                                <x-fx.menu-item href="{{ route('brokers.index') }}" :active="request()->routeIs('brokers.index')">Lista</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('brokers.create') }}" :active="request()->routeIs('brokers.create')">Novo</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('brokers.case-types.index') }}" :active="request()->routeIs('brokers.case-types.*')">Tipos de caso</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('brokers.reports') }}" :active="request()->routeIs('brokers.reports')">Relatórios</x-fx.menu-item>
                            </div>
                        </div>

                        {{-- Sociedade --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <x-fx.menu-item href="{{ route('partnership.index') }}" :active="request()->routeIs('partnership.*')">
                                🏢 Sociedade
                                <svg class="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </x-fx.menu-item>
                            <div x-show="open" x-cloak class="absolute top-full left-0 mt-1 w-40 bg-mono-white border border-mono-100 rounded-md shadow-lg z-50 p-xs flex flex-col gap-xxxs">
                                <x-fx.menu-item href="{{ route('partnership.index') }}" :active="request()->routeIs('partnership.index')">Lista</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('partnership.create') }}" :active="request()->routeIs('partnership.create')">Nova</x-fx.menu-item>
                            </div>
                        </div>

                        {{-- Investimentos --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <x-fx.menu-item href="{{ route('investments.dashboard') }}" :active="request()->routeIs('investments.*')">
                                📈 Investimentos
                                <svg class="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </x-fx.menu-item>
                            <div x-show="open" x-cloak class="absolute top-full left-0 mt-1 w-44 bg-mono-white border border-mono-100 rounded-md shadow-lg z-50 p-xs flex flex-col gap-xxxs">
                                <x-fx.menu-item href="{{ route('investments.dashboard') }}" :active="request()->routeIs('investments.dashboard')">Visão geral</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('investments.positions') }}" :active="request()->routeIs('investments.positions')">Posições</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('investments.operations.index') }}" :active="request()->routeIs('investments.operations.*')">Operações</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('investments.dividends.index') }}" :active="request()->routeIs('investments.dividends.*')">Proventos</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('investments.assets.index') }}" :active="request()->routeIs('investments.assets.*')">Ativos</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('investments.reports') }}" :active="request()->routeIs('investments.reports')">Rentabilidade</x-fx.menu-item>
                            </div>
                        </div>

                        {{-- Requisitórios --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <x-fx.menu-item href="{{ route('writs.kanban') }}" :active="request()->routeIs('writs.*')">
                                ⚖️ Requisitórios
                                <svg class="inline w-3 h-3 ml-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </x-fx.menu-item>
                            <div x-show="open" x-cloak class="absolute top-full left-0 mt-1 w-40 bg-mono-white border border-mono-100 rounded-md shadow-lg z-50 p-xs flex flex-col gap-xxxs">
                                <x-fx.menu-item href="{{ route('writs.kanban') }}" :active="request()->routeIs('writs.kanban')">Pipeline</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('writs.create') }}" :active="request()->routeIs('writs.create')">Novo</x-fx.menu-item>
                                <x-fx.menu-item href="{{ route('writs.reports') }}" :active="request()->routeIs('writs.reports')">Relatórios</x-fx.menu-item>
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
                        <x-fx.menu-item href="{{ route('profile') }}" :active="request()->routeIs('profile')">
                            ⚙️
                        </x-fx.menu-item>

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

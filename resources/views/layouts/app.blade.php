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
        <div class="min-h-screen flex">
            {{-- Sidebar --}}
            <aside class="hidden md:flex flex-col w-64 shrink-0 bg-mono-white border-r border-mono-100 p-md">
                <div class="flex items-center gap-xs px-md py-sm mb-md">
                    <span class="inline-block w-8 h-8 rounded-full bg-primary-500"></span>
                    <span class="font-bold text-md text-mono-900">Cassio Finance</span>
                </div>

                <nav class="flex-1 flex flex-col gap-xxxs">
                    <x-fx.menu-item href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">
                        <x-slot:icon>🏠</x-slot:icon>
                        Dashboard
                    </x-fx.menu-item>
                    <x-fx.menu-item href="{{ route('banking.dashboard') }}" :active="request()->routeIs('banking.*')">
                        <x-slot:icon>💰</x-slot:icon>
                        Financeiro
                    </x-fx.menu-item>
                    @if (request()->routeIs('banking.*'))
                        <div class="ml-md flex flex-col gap-xxxs">
                            <x-fx.menu-item href="{{ route('banking.transactions.index') }}" :active="request()->routeIs('banking.transactions.*')">Lançamentos</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('banking.accounts.index') }}" :active="request()->routeIs('banking.accounts.*')">Contas</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('banking.cards.index') }}" :active="request()->routeIs('banking.cards.*')">Cartões</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('banking.recurring.index') }}" :active="request()->routeIs('banking.recurring.*')">Recorrências</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('banking.categories.index') }}" :active="request()->routeIs('banking.categories.*')">Categorias</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('banking.reports.cashflow') }}" :active="request()->routeIs('banking.reports.*')">Fluxo de caixa</x-fx.menu-item>
                        </div>
                    @endif
                    <x-fx.menu-item href="{{ route('brokers.index') }}" :active="request()->routeIs('brokers.*')">
                        <x-slot:icon>👥</x-slot:icon>
                        Corretores
                    </x-fx.menu-item>
                    @if (request()->routeIs('brokers.*'))
                        <div class="ml-md flex flex-col gap-xxxs">
                            <x-fx.menu-item href="{{ route('brokers.index') }}" :active="request()->routeIs('brokers.index')">Lista</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('brokers.create') }}" :active="request()->routeIs('brokers.create')">Novo</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('brokers.case-types.index') }}" :active="request()->routeIs('brokers.case-types.*')">Tipos de caso</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('brokers.reports') }}" :active="request()->routeIs('brokers.reports')">Relatórios</x-fx.menu-item>
                        </div>
                    @endif
                    <x-fx.menu-item href="{{ route('partnership.index') }}" :active="request()->routeIs('partnership.*')">
                        <x-slot:icon>🏢</x-slot:icon>
                        Sociedade
                    </x-fx.menu-item>
                    @if (request()->routeIs('partnership.*'))
                        <div class="ml-md flex flex-col gap-xxxs">
                            <x-fx.menu-item href="{{ route('partnership.index') }}" :active="request()->routeIs('partnership.index')">Lista</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('partnership.create') }}" :active="request()->routeIs('partnership.create')">Nova</x-fx.menu-item>
                        </div>
                    @endif
                    <x-fx.menu-item href="{{ route('investments.dashboard') }}" :active="request()->routeIs('investments.*')">
                        <x-slot:icon>📈</x-slot:icon>
                        Investimentos
                    </x-fx.menu-item>
                    @if (request()->routeIs('investments.*'))
                        <div class="ml-md flex flex-col gap-xxxs">
                            <x-fx.menu-item href="{{ route('investments.dashboard') }}" :active="request()->routeIs('investments.dashboard')">Visão geral</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('investments.positions') }}" :active="request()->routeIs('investments.positions')">Posições</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('investments.operations.index') }}" :active="request()->routeIs('investments.operations.*')">Operações</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('investments.dividends.index') }}" :active="request()->routeIs('investments.dividends.*')">Proventos</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('investments.assets.index') }}" :active="request()->routeIs('investments.assets.*')">Ativos</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('investments.reports') }}" :active="request()->routeIs('investments.reports')">Rentabilidade</x-fx.menu-item>
                        </div>
                    @endif
                    <x-fx.menu-item href="{{ route('writs.kanban') }}" :active="request()->routeIs('writs.*')">
                        <x-slot:icon>⚖️</x-slot:icon>
                        Requisitórios
                    </x-fx.menu-item>
                    @if (request()->routeIs('writs.*'))
                        <div class="ml-md flex flex-col gap-xxxs">
                            <x-fx.menu-item href="{{ route('writs.kanban') }}" :active="request()->routeIs('writs.kanban')">Pipeline</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('writs.create') }}" :active="request()->routeIs('writs.create')">Novo</x-fx.menu-item>
                            <x-fx.menu-item href="{{ route('writs.reports') }}" :active="request()->routeIs('writs.reports')">Relatórios</x-fx.menu-item>
                        </div>
                    @endif

                    <hr class="fx-divider" />

                    <x-fx.menu-item href="{{ route('profile') }}" :active="request()->routeIs('profile')">
                        <x-slot:icon>⚙️</x-slot:icon>
                        Configurações
                    </x-fx.menu-item>
                </nav>

                <div class="mt-md px-md py-sm text-xxs text-mono-600">
                    v1.0 · {{ now()->format('Y') }}
                </div>
            </aside>

            {{-- Main column --}}
            <div class="flex-1 flex flex-col min-w-0">
                {{-- Topbar --}}
                <header class="h-16 flex items-center justify-between gap-md px-lg bg-mono-white border-b border-mono-100">
                    @if (isset($header))
                        <h1 class="text-lg font-semibold text-mono-900 truncate">{{ $header }}</h1>
                    @else
                        <span class="text-sm text-mono-600">{{ auth()->user()->name ?? '' }}</span>
                    @endif

                    <div class="flex items-center gap-xs">
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
                </header>

                {{-- Content --}}
                <main class="flex-1 p-lg">
                    {{ $slot }}
                </main>

                {{-- Atalhos de teclado --}}
                <footer class="px-lg py-xs text-xxs text-mono-600 border-t border-mono-100 bg-mono-white flex flex-wrap gap-md">
                    <span><span class="fx-kbd">n</span> <span class="fx-kbd">r</span> nova receita</span>
                    <span><span class="fx-kbd">n</span> <span class="fx-kbd">d</span> nova despesa</span>
                    <span><span class="fx-kbd">n</span> <span class="fx-kbd">t</span> transferência</span>
                    <span><span class="fx-kbd">n</span> <span class="fx-kbd">w</span> requisitório</span>
                    <span><span class="fx-kbd">g</span> <span class="fx-kbd">d</span> dashboard</span>
                </footer>
            </div>
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

@php
    $topItem = 'inline-flex h-10 shrink-0 items-center gap-2 rounded-pill px-3 text-sm font-semibold transition-colors';
    $topActive = 'bg-primary-100 text-primary-500';
    $topInactive = 'text-mono-600 hover:bg-mono-100 hover:text-mono-900';
    $dropdownItem = 'flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-mono-50';
@endphp

<nav class="hidden min-w-0 flex-1 overflow-x-auto lg:block">
    <div class="flex w-max items-center gap-1.5">
        <a href="{{ route('dashboard') }}" class="{{ $topItem }} {{ request()->routeIs('dashboard') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">dashboard</span>
            Dashboard
        </a>

        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" class="{{ $topItem }} {{ request()->routeIs('banking.*') ? $topActive : $topInactive }}" @click="open = !open">
                <span class="material-icons-outlined text-[20px]">account_balance_wallet</span>
                Financeiro
                <span class="material-icons-outlined text-[18px]">expand_more</span>
            </button>

            <div x-show="open" x-transition class="absolute left-0 top-12 z-dropdown w-64 rounded-xl border border-mono-100 bg-mono-white py-2 shadow-dropdown" style="display: none;">
                <a href="{{ route('banking.dashboard') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.dashboard') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">dashboard</span>
                    Visão geral
                </a>
                <a href="{{ route('banking.transactions.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.transactions.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">receipt_long</span>
                    Lançamentos
                </a>
                <a href="{{ route('banking.accounts.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.accounts.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">account_balance</span>
                    Contas
                </a>
                <a href="{{ route('banking.cards.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.cards.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">credit_card</span>
                    Cartões
                </a>
                <a href="{{ route('banking.recurring.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.recurring.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">autorenew</span>
                    Recorrências
                </a>
                <a href="{{ route('banking.categories.index') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.categories.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">category</span>
                    Categorias
                </a>
                <a href="{{ route('banking.reports.cashflow') }}" class="{{ $dropdownItem }} {{ request()->routeIs('banking.reports.*') ? 'font-semibold text-primary-500' : 'text-mono-900' }}">
                    <span class="material-icons-outlined text-[18px] text-mono-400">query_stats</span>
                    Fluxo de caixa
                </a>
            </div>
        </div>

        <a href="{{ route('writs.kanban') }}" class="{{ $topItem }} {{ request()->routeIs('writs.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">gavel</span>
            Requisitórios
        </a>

        <a href="{{ route('brokers.index') }}" class="{{ $topItem }} {{ request()->routeIs('brokers.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">groups</span>
            Corretores
        </a>

        <a href="{{ route('broadcasts.index') }}" class="{{ $topItem }} {{ request()->routeIs('broadcasts.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">campaign</span>
            Transmissão
        </a>

        <a href="{{ route('investments.dashboard') }}" class="{{ $topItem }} {{ request()->routeIs('investments.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">trending_up</span>
            Investimento
        </a>

        <a href="{{ route('partnership.index') }}" class="{{ $topItem }} {{ request()->routeIs('partnership.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">business</span>
            Sociedade
        </a>

        <a href="{{ route('contacts.index') }}" class="{{ $topItem }} {{ request()->routeIs('contacts.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">person</span>
            Contatos
        </a>
    </div>
</nav>

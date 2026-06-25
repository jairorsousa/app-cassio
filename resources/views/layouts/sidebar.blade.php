@php
    $topItem = 'inline-flex h-10 shrink-0 items-center gap-2 rounded-pill px-3 text-sm font-semibold transition-colors';
    $topActive = 'bg-primary-100 text-primary-500';
    $topInactive = 'text-mono-600 hover:bg-mono-100 hover:text-mono-900';
@endphp

<nav class="hidden min-w-0 flex-1 overflow-x-auto lg:block">
    <div class="flex w-max items-center gap-1.5">
        <a href="{{ route('dashboard') }}" class="{{ $topItem }} {{ request()->routeIs('dashboard') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">dashboard</span>
            Dashboard
        </a>

        <a href="{{ route('banking.dashboard') }}" class="{{ $topItem }} {{ request()->routeIs('banking.*') ? $topActive : $topInactive }}">
            <span class="material-icons-outlined text-[20px]">account_balance_wallet</span>
            Financeiro
        </a>

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

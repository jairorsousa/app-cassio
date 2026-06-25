@php
    $tab = 'inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold transition-colors';
    $activeTab = 'border-primary-500 text-primary-500';
    $inactiveTab = 'border-transparent text-mono-600 hover:border-mono-200 hover:text-mono-900';
@endphp

<nav class="flex flex-wrap gap-6 border-b border-mono-100">
    <a
        href="{{ route('banking.dashboard') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.dashboard') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">dashboard</span>
        Visão geral
    </a>
    <a
        href="{{ route('banking.transactions.index') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.transactions.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">receipt_long</span>
        Lançamentos
    </a>
    <a
        href="{{ route('banking.accounts.index') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.accounts.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">account_balance</span>
        Contas
    </a>
    <a
        href="{{ route('banking.cards.index') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.cards.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">credit_card</span>
        Cartões
    </a>
    <a
        href="{{ route('banking.recurring.index') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.recurring.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">autorenew</span>
        Recorrências
    </a>
    <a
        href="{{ route('banking.categories.index') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.categories.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">category</span>
        Categorias
    </a>
    <a
        href="{{ route('banking.reports.cashflow') }}"
        class="{{ $tab }} {{ request()->routeIs('banking.reports.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">query_stats</span>
        Fluxo de caixa
    </a>
</nav>
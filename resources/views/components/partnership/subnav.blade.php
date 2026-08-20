@props(['partnership'])

@php
    $tab = 'inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold transition-colors';
    $activeTab = 'border-primary-500 text-primary-500';
    $inactiveTab = 'border-transparent text-mono-600 hover:border-mono-200 hover:text-mono-900';
    $action = 'inline-flex items-center gap-1.5 pb-3 text-sm font-medium text-mono-600 transition-colors hover:text-mono-900';
@endphp

<nav class="flex flex-wrap items-start gap-x-6 gap-y-2 border-b border-mono-100">
    <a
        href="{{ route('partnership.show', $partnership) }}"
        class="{{ $tab }} {{ request()->routeIs('partnership.show') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">dashboard</span>
        Visão geral
    </a>
    <a
        href="{{ route('partnership.contributions.index', $partnership) }}"
        class="{{ $tab }} {{ request()->routeIs('partnership.contributions.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">savings</span>
        Aportes
    </a>
    <a
        href="{{ route('partnership.expenses.index', $partnership) }}"
        class="{{ $tab }} {{ request()->routeIs('partnership.expenses.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">receipt_long</span>
        Despesas
    </a>
    <a
        href="{{ route('partnership.distributions.index', $partnership) }}"
        class="{{ $tab }} {{ request()->routeIs('partnership.distributions.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">paid</span>
        Distribuições
    </a>
    <a
        href="{{ route('partnership.reports', $partnership) }}"
        class="{{ $tab }} {{ request()->routeIs('partnership.reports') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">query_stats</span>
        Rentabilidade
    </a>

    <div class="flex basis-full items-center gap-5 sm:ml-auto sm:basis-auto">
        <a href="{{ route('partnership.edit', $partnership) }}" class="{{ $action }}">
            <span class="material-icons-outlined text-[18px]">edit</span>
            Editar dados
        </a>
        <a href="{{ route('partnership.index') }}" class="{{ $action }}">
            <span class="material-icons-outlined text-[18px]">arrow_back</span>
            Sociedades
        </a>
    </div>
</nav>

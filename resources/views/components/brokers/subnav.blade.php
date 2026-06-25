@php
    $tab = 'inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold transition-colors';
    $activeTab = 'border-primary-500 text-primary-500';
    $inactiveTab = 'border-transparent text-mono-600 hover:border-mono-200 hover:text-mono-900';
@endphp

<nav class="flex flex-wrap gap-6 border-b border-mono-100">
    <a
        href="{{ route('brokers.index') }}"
        class="{{ $tab }} {{ request()->routeIs('brokers.index', 'brokers.show', 'brokers.edit', 'brokers.advances.*', 'brokers.commissions.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">groups</span>
        Corretores
    </a>
    <a
        href="{{ route('brokers.case-types.index') }}"
        class="{{ $tab }} {{ request()->routeIs('brokers.case-types.*') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">folder_open</span>
        Tipos de caso
    </a>
    <a
        href="{{ route('brokers.reports') }}"
        class="{{ $tab }} {{ request()->routeIs('brokers.reports') ? $activeTab : $inactiveTab }}"
    >
        <span class="material-icons-outlined text-[18px]">query_stats</span>
        Relatórios
    </a>
</nav>
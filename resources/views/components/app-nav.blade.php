@props(['layout' => 'desktop'])

@php
    $items = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['route' => 'banking.dashboard', 'match' => 'banking.*', 'label' => 'Financeiro', 'icon' => 'account_balance_wallet'],
        ['route' => 'writs.kanban', 'match' => 'writs.*', 'label' => 'Requisitórios', 'icon' => 'gavel'],
        ['route' => 'brokers.index', 'match' => 'brokers.*', 'label' => 'Corretores', 'icon' => 'groups'],
        ['route' => 'broadcasts.index', 'match' => 'broadcasts.*', 'label' => 'Transmissão', 'icon' => 'campaign'],
        ['route' => 'investments.dashboard', 'match' => 'investments.*', 'label' => 'Investimento', 'icon' => 'trending_up'],
        ['route' => 'partnership.index', 'match' => 'partnership.*', 'label' => 'Sociedade', 'icon' => 'business'],
        ['route' => 'contacts.index', 'match' => 'contacts.*', 'label' => 'Contatos', 'icon' => 'person'],
    ];

    $base = $layout === 'mobile'
        ? 'flex h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition-colors'
        : 'inline-flex h-10 shrink-0 items-center gap-2 rounded-pill px-3 text-sm font-semibold transition-colors';
@endphp

@foreach ($items as $item)
    @php
        $active = request()->routeIs($item['match']);
        $classes = $base.' '.($active ? 'bg-primary-100 text-primary-500' : 'text-mono-600 hover:bg-mono-100 hover:text-mono-900');
    @endphp
    <a
        href="{{ route($item['route']) }}"
        class="{{ $classes }}"
        @if ($layout === 'mobile') @click="sidebarOpen = false" @endif
    >
        <span class="material-icons-outlined text-[20px]">{{ $item['icon'] }}</span>
        {{ $item['label'] }}
    </a>
@endforeach

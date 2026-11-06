@props(['layout' => 'desktop'])

@php
    $items = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['route' => 'banking.dashboard', 'match' => 'banking.*', 'label' => 'Financeiro', 'icon' => 'account_balance_wallet'],
        ['route' => 'investments.dashboard', 'match' => 'investments.*', 'label' => 'Investimentos', 'icon' => 'trending_up'],
        ['route' => 'writs.kanban', 'match' => 'writs.*', 'label' => 'Requisitório', 'icon' => 'gavel'],
        ['route' => 'brokers.index', 'match' => 'brokers.*', 'label' => 'Corretores', 'icon' => 'groups'],
        ['route' => 'contacts.index', 'match' => 'contacts.*', 'label' => 'Contatos', 'icon' => 'person'],
        ['route' => 'partnership.index', 'match' => 'partnership.*', 'label' => 'Sociedade', 'icon' => 'business'],
        ['route' => 'broadcasts.index', 'match' => 'broadcasts.*', 'label' => 'Transmissão', 'icon' => 'campaign'],
    ];

    $base = $layout === 'mobile'
        ? 'flex h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition-colors'
        : 'inline-flex h-10 shrink-0 items-center gap-2 rounded-pill px-3 text-sm font-semibold transition-colors';
@endphp

@php
    $items = array_filter($items, fn ($i) => \App\Support\Access::allows(auth()->user(), \App\Support\Access::forRoute($i['route'])));
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
@php($settingsActive = request()->routeIs('settings*'))
<a
    href="{{ route('settings') }}"
    data-nav-settings
    aria-label="Configurações"
    title="Configurações"
    class="{{ $layout === 'mobile' ? $base : 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition-colors' }} {{ $settingsActive ? 'bg-primary-100 text-primary-500' : 'text-mono-600 hover:bg-mono-100 hover:text-mono-900' }}"
    @if ($layout === 'mobile') @click="sidebarOpen = false" @endif
>
    <span class="material-icons-outlined text-[20px]">settings</span>
    @if ($layout === 'mobile') Configurações @endif
</a>

@props([
    'href' => '#',
    'active' => false,
    'icon' => null,
])

@php
    $baseClasses = 'flex items-center gap-space-3 px-space-4 h-[44px] rounded-md text-fs-14 transition-colors duration-150';
    $activeClasses = $active
        ? 'bg-[rgba(240,185,11,0.1)] text-cryptex-brand-400 font-semibold'
        : 'text-cryptex-text-primary hover:bg-cryptex-bg-tertiary';
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => $baseClasses . ' ' . $activeClasses]) }}
>
    @if ($icon)
        <span class="w-5 h-5 flex-shrink-0 flex items-center justify-center">{!! $icon !!}</span>
    @endif
    <span>{{ $slot }}</span>
</a>

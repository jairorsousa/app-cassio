@props([
    'variant' => 'primary',
    'size' => null,
    'type' => 'button',
    'href' => null,
])

@php
    $classes = 'fx-btn fx-btn--' . $variant;
    if ($size === 'sm') {
        $classes .= ' fx-btn--sm';
    }
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif

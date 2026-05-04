@props([
    'variant' => 'primary',
    'size' => 'default',
    'type' => 'button',
    'href' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-pill font-semibold transition-all duration-200 active:scale-[.97] focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed';

    $sizes = [
        'default' => 'h-11 px-6 text-sm',
        'sm' => 'h-9 px-4 text-[13px]',
    ];

    $variants = [
        'primary' => 'bg-primary-500 text-white hover:bg-primary-600',
        'standard' => 'border border-mono-200 bg-transparent text-mono-900 hover:bg-mono-50',
        'mono' => 'bg-mono-100 text-mono-900 hover:bg-mono-200',
        'text' => 'bg-transparent text-mono-900 hover:bg-mono-50',
        'danger' => 'bg-error text-white hover:bg-down',
    ];

    $classes = $base . ' ' . ($sizes[$size] ?? $sizes['default']) . ' ' . ($variants[$variant] ?? $variants['primary']);
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

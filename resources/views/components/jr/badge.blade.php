@props([
    'variant' => 'neutral',
    'size' => 'default',
])

@php
    $base = 'inline-flex items-center rounded-pill font-semibold';
    $sizes = [
        'default' => 'px-2.5 py-1 text-xs',
        'sm' => 'px-2 py-0.5 text-[10px]',
    ];
    $variants = [
        'up' => 'bg-up-bg text-up',
        'down' => 'bg-down-bg text-down',
        'success' => 'bg-success-bg text-success',
        'error' => 'bg-down-bg text-error',
        'info' => 'bg-info-bg text-info',
        'primary' => 'bg-primary-100 text-primary-500',
        'neutral' => 'bg-mono-100 text-mono-600',
    ];
@endphp

<span {{ $attributes->merge(['class' => $base . ' ' . ($sizes[$size] ?? $sizes['default']) . ' ' . ($variants[$variant] ?? $variants['neutral'])]) }}>
    {{ $slot }}
</span>

@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
])

@php
    $baseClasses = 'inline-flex items-center justify-center font-semibold rounded-sm transition-all duration-150 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed';
    
    $sizeClasses = match($size) {
        'sm' => 'py-[8px] px-[12px] text-fs-12',
        'lg' => 'py-[16px] px-[24px] text-fs-16',
        default => 'py-[12px] px-[20px] text-fs-14',
    };

    $variantClasses = match($variant) {
        'primary' => 'bg-cryptex-brand-400 text-cryptex-text-inverse hover:-translate-y-[1px] hover:shadow-glow',
        'secondary' => 'bg-cryptex-bg-elevated text-cryptex-text-primary hover:bg-cryptex-border-subtle',
        'ghost' => 'bg-transparent text-cryptex-text-secondary hover:text-cryptex-text-primary',
        'success' => 'bg-cryptex-green-400 text-white hover:bg-cryptex-green-600',
        'danger' => 'bg-cryptex-red-400 text-white hover:bg-cryptex-red-600',
        default => 'bg-cryptex-bg-elevated text-cryptex-text-primary',
    };

    $classes = $baseClasses . ' ' . $sizeClasses . ' ' . $variantClasses;
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

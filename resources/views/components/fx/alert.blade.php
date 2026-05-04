@props([
    'variant' => 'info',
    'dismissible' => false,
])

@php
    $baseClasses = 'flex items-center gap-space-4 p-space-4 rounded-md text-fs-14 border';
    $variantClasses = match($variant) {
        'success' => 'bg-[rgba(2,192,118,0.1)] border-[rgba(2,192,118,0.2)] text-cryptex-green-500',
        'error' => 'bg-[rgba(246,70,93,0.1)] border-[rgba(246,70,93,0.2)] text-cryptex-red-500',
        'warning' => 'bg-[rgba(240,185,11,0.1)] border-[rgba(240,185,11,0.2)] text-cryptex-brand-400',
        'info' => 'bg-[rgba(51,117,187,0.1)] border-[rgba(51,117,187,0.2)] text-cryptex-blue-400',
        default => 'bg-cryptex-bg-elevated border-cryptex-border-subtle text-cryptex-text-primary',
    };
@endphp

<div
    x-data="{ open: true }"
    x-show="open"
    {{ $attributes->merge(['class' => $baseClasses . ' ' . $variantClasses]) }}
>
    <div class="flex-1">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" class="text-cryptex-text-secondary hover:text-cryptex-text-primary" @click="open = false" aria-label="Fechar">×</button>
    @endif
</div>

@props(['variant' => 'neutral'])

@php
    $baseClasses = 'inline-flex items-center px-[8px] py-[3px] rounded-xs text-[11px] font-mono uppercase tracking-[0.04em] font-medium whitespace-nowrap';
    
    $variantClasses = match($variant) {
        'success' => 'bg-[rgba(2,192,118,0.15)] text-cryptex-green-500',
        'danger' => 'bg-[rgba(246,70,93,0.15)] text-cryptex-red-500',
        'warning' => 'bg-[rgba(240,185,11,0.15)] text-cryptex-brand-400',
        'info' => 'bg-[rgba(51,117,187,0.2)] text-[#6699D3]',
        default => 'bg-cryptex-bg-elevated text-cryptex-text-secondary',
    };
@endphp

<span {{ $attributes->merge(['class' => $baseClasses . ' ' . $variantClasses]) }}>
    {{ $slot }}
</span>

@props(['active' => false])

@php
    $baseClasses = 'inline-flex items-center px-[16px] h-[36px] rounded-full text-fs-12 font-medium transition-colors duration-150 border';
    $activeClasses = $active 
        ? 'bg-cryptex-brand-400 border-cryptex-brand-400 text-cryptex-text-inverse' 
        : 'bg-cryptex-bg-secondary border-cryptex-border-default text-cryptex-text-primary hover:border-cryptex-brand-400 hover:text-cryptex-brand-400';
@endphp

<button
    type="button"
    {{ $attributes->merge(['class' => $baseClasses . ' ' . $activeClasses]) }}
>
    {{ $slot }}
</button>

@props([
    'type' => 'text',
    'name' => null,
    'id' => null,
    'state' => null,
    'label' => null,
    'leadingIcon' => null,
    'trailingIcon' => null,
    'numeric' => false,
])

@php
    $inputId = $id ?? $name;
    // Base wrapper classes
    $wrapperClasses = 'relative flex items-center gap-space-2 h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border transition-all duration-150 focus-within:bg-cryptex-bg-secondary focus-within:shadow-[0_0_0_3px_rgba(240,185,11,0.12)]';
    
    // Add logic for error state checking
    $hasError = $name ? $errors->has($name) : false;
    
    if ($hasError || $state === 'error') {
        $wrapperClasses .= ' border-cryptex-red-400 focus-within:border-cryptex-red-400';
    } else if ($state === 'success') {
        $wrapperClasses .= ' border-cryptex-green-400 focus-within:border-cryptex-green-400';
    } else {
        $wrapperClasses .= ' border-cryptex-border-default hover:border-cryptex-border-strong focus-within:border-cryptex-brand-400';
    }

    if ($state === 'disabled') {
        $wrapperClasses .= ' opacity-50 pointer-events-none';
    }
    
    $inputClasses = 'flex-1 bg-transparent border-0 p-0 text-fs-14 text-cryptex-text-primary placeholder:text-cryptex-text-tertiary focus:outline-none focus:ring-0';
    if ($numeric || $type === 'number') {
        $inputClasses .= ' font-mono [font-variant-numeric:tabular-nums]';
    }
@endphp

<div class="flex flex-col gap-space-1">
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="block text-fs-12 font-medium text-cryptex-text-tertiary">{{ $label }}</label>
    @endif
    <div class="{{ $wrapperClasses }}">
        @if ($leadingIcon)
            <span class="text-cryptex-text-tertiary w-5 h-5 flex-shrink-0 flex items-center justify-center">{!! $leadingIcon !!}</span>
        @endif
        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($inputId) id="{{ $inputId }}" @endif
            {{ $attributes->merge(['class' => $inputClasses]) }}
        />
        @if ($trailingIcon)
            <span class="text-cryptex-text-tertiary w-5 h-5 flex-shrink-0 flex items-center justify-center font-mono text-fs-14">{!! $trailingIcon !!}</span>
        @endif
    </div>
    @if ($name && $errors->has($name))
        <p class="text-fs-12 text-cryptex-red-500 mt-1">{{ $errors->first($name) }}</p>
    @endif
</div>

@props([
    'label' => null,
    'icon' => null,
    'error' => null,
    'success' => false,
    'helper' => null,
    'type' => 'text',
    'name' => null,
    'id' => null,
])

@php
    $inputId = $id ?? $name;
    $hasError = filled($error) || ($name && $errors->has($name));
    $message = $error ?: ($name ? $errors->first($name) : null);

    $wrapper = 'flex h-12 items-center gap-2 rounded-pill border bg-mono-white px-4 transition-all duration-200 hover:border-mono-300 focus-within:border-primary-500 focus-within:shadow-[0_0_0_3px_rgba(255,111,0,.1)]';
    $wrapper .= $hasError ? ' border-error' : ($success ? ' border-success' : ' border-mono-200');
@endphp

<div class="space-y-2">
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="block text-sm font-medium text-mono-600">
            {{ $label }}
        </label>
    @endif

    <div class="{{ $wrapper }}">
        @if ($icon)
            <span class="material-icons-outlined text-[20px] text-mono-300">{{ $icon }}</span>
        @endif

        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($inputId) id="{{ $inputId }}" @endif
            {{ $attributes->merge(['class' => 'min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-mono-900 placeholder:text-mono-300 focus:outline-none focus:ring-0']) }}
        />

        @if ($hasError)
            <span class="material-icons-outlined text-[20px] text-error">error</span>
        @elseif ($success)
            <span class="material-icons-outlined text-[20px] text-success">check_circle</span>
        @endif
    </div>

    @if ($message)
        <p class="text-xs font-medium text-error">{{ $message }}</p>
    @elseif ($helper)
        <p class="text-xs text-mono-600">{{ $helper }}</p>
    @endif
</div>

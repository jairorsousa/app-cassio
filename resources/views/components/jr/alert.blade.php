@props([
    'variant' => 'info',
    'dismissible' => true,
])

@php
    $variants = [
        'success' => ['bg-success-bg text-success border-success', 'check_circle'],
        'error' => ['bg-down-bg text-error border-error', 'error'],
        'info' => ['bg-info-bg text-info border-info', 'info'],
    ];

    [$classes, $icon] = $variants[$variant] ?? $variants['info'];
@endphp

<div x-data="{ visible: true }" x-show="visible" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm ' . $classes]) }}>
    <span class="material-icons-outlined mt-0.5 text-[20px]">{{ $icon }}</span>
    <div class="min-w-0 flex-1">{{ $slot }}</div>

    @if ($dismissible)
        <button type="button" class="rounded-lg p-1 transition-colors hover:bg-mono-100" @click="visible = false" aria-label="Fechar alerta">
            <span class="material-icons-outlined text-[18px]">close</span>
        </button>
    @endif
</div>

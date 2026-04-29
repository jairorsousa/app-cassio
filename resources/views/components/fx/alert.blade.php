@props([
    'variant' => 'info',
    'dismissible' => false,
])

<div
    x-data="{ open: true }"
    x-show="open"
    {{ $attributes->merge(['class' => 'fx-alert fx-alert--' . $variant]) }}
>
    <div class="flex-1">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" class="fx-btn fx-btn--text" @click="open = false" aria-label="Fechar">×</button>
    @endif
</div>

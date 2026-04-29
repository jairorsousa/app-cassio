@props(['variant' => 'neutral'])

<span {{ $attributes->merge(['class' => 'fx-badge fx-badge--' . $variant]) }}>
    {{ $slot }}
</span>

@props(['active' => false])

<button
    type="button"
    {{ $attributes->merge(['class' => 'fx-pill' . ($active ? ' fx-pill--active' : '')]) }}
>
    {{ $slot }}
</button>

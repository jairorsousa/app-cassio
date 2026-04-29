@props([
    'href' => '#',
    'active' => false,
    'icon' => null,
])

<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => 'fx-menu-item' . ($active ? ' fx-menu-item--active' : '')]) }}
>
    @if ($icon)
        <span class="fx-menu-icon">{!! $icon !!}</span>
    @endif
    <span>{{ $slot }}</span>
</a>

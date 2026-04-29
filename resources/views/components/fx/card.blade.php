@props(['title' => null])

<div {{ $attributes->merge(['class' => 'fx-card']) }}>
    @if ($title)
        <div class="fx-card-title">{{ $title }}</div>
    @endif
    {{ $slot }}
</div>

@props([
    'padding' => true,
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-mono-100 bg-mono-white shadow-card' . ($padding ? ' p-6' : '')]) }}>
    {{ $slot }}
</div>

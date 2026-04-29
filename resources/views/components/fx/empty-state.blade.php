@props([
    'icon' => null,
    'title' => 'Nada por aqui ainda',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-xl px-md']) }}>
    @if ($icon)
        <div class="text-3xl mb-xs opacity-60">{!! $icon !!}</div>
    @endif
    <div class="text-md font-semibold text-mono-900">{{ $title }}</div>
    @if ($description)
        <div class="text-sm text-mono-600 mt-xxs max-w-md">{{ $description }}</div>
    @endif
    @if ($actionLabel && $actionHref)
        <a href="{{ $actionHref }}" class="fx-btn fx-btn--primary mt-sm">{{ $actionLabel }}</a>
    @endif
    {{ $slot }}
</div>

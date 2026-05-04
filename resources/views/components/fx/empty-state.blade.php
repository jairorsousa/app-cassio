@props([
    'icon' => null,
    'title' => 'Nada por aqui ainda',
    'description' => null,
    'actionLabel' => null,
    'actionHref' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center py-space-12 px-space-5']) }}>
    @if ($icon)
        <div class="text-[32px] mb-space-3 text-cryptex-text-tertiary">{!! $icon !!}</div>
    @endif
    <div class="text-fs-16 font-semibold text-cryptex-text-primary">{{ $title }}</div>
    @if ($description)
        <div class="text-fs-14 text-cryptex-text-secondary mt-space-2 max-w-md">{{ $description }}</div>
    @endif
    @if ($actionLabel && $actionHref)
        <x-fx.button href="{{ $actionHref }}" class="mt-space-4">{{ $actionLabel }}</x-fx.button>
    @endif
    {{ $slot }}
</div>

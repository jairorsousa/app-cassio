@props([
    'icon' => 'inbox',
    'title' => 'Nenhum item encontrado.',
    'description' => null,
])

<x-jr.card>
    <div {{ $attributes->merge(['class' => 'py-12 text-center']) }}>
        <span class="material-icons-outlined text-[48px] text-mono-300">{{ $icon }}</span>
        <p class="mt-2 text-sm font-medium text-mono-600">{{ $title }}</p>
        @if ($description)
            <p class="mx-auto mt-2 max-w-md text-sm text-mono-600">{{ $description }}</p>
        @endif
        @if (!$slot->isEmpty())
            <div class="mt-4">{{ $slot }}</div>
        @endif
    </div>
</x-jr.card>

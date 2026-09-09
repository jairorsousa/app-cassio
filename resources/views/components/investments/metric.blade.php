@props(['label', 'value', 'hint' => '', 'icon' => 'payments', 'positive' => null])
<x-jr.card>
    <div class="mb-4 flex items-center justify-between gap-2"><span class="text-sm text-mono-600">{{ $label }}</span><span class="material-icons-outlined rounded-xl bg-primary-100 p-2 text-primary-500">{{ $icon }}</span></div>
    <div @class(['text-2xl font-bold tracking-tight tabular-nums', 'text-up' => $positive === true, 'text-down' => $positive === false, 'text-mono-900' => $positive === null])>{{ $value }}</div>
    <p class="mt-2 text-xs text-mono-600">{{ $hint }}</p>
</x-jr.card>

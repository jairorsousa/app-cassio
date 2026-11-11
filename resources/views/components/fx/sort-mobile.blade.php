@props([
    'list' => 'transactions',
    'sortBy' => null,
    'sortDir' => 'desc',
    'sorts' => null,
    'id' => null,
    'primary' => false,
])
@php
    if (is_array($sorts) && isset($sorts[$list]['by'])) {
        $sortBy = $sorts[$list]['by'];
        $sortDir = $sorts[$list]['dir'] ?? 'desc';
    }
    $id = $id ?: 'sort-'.$list;
    $columns = \App\Support\UserPreferences::sortColumns($list);
@endphp
@if (count($columns))
    <div {{ $attributes->class(['mb-3 flex items-center gap-2 sm:hidden']) }} data-sort-mobile data-sort-list="{{ $list }}">
        <label for="{{ $id }}" class="whitespace-nowrap text-sm text-mono-600">Ordenar por</label>
        @if ($primary)
            <select id="{{ $id }}" wire:model.live="sortBy" class="h-11 min-w-0 flex-1 rounded-sm border border-mono-200 bg-mono-white px-3 text-sm text-mono-900" data-sort-select>
                @foreach ($columns as $col => $label)<option value="{{ $col }}">{{ $label }}</option>@endforeach
            </select>
            <button type="button" wire:click="toggleSortDir" class="inline-flex h-11 min-w-[88px] items-center justify-center gap-1 rounded-sm border border-mono-200 bg-mono-white px-3 text-sm font-semibold text-mono-900" aria-label="Inverter ordem ({{ $sortDir === 'asc' ? 'crescente' : 'decrescente' }})" data-sort-dir="{{ $sortDir }}">
                <span class="material-icons-outlined text-[18px]" aria-hidden="true">{{ $sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>{{ $sortDir === 'asc' ? 'Cresc.' : 'Decresc.' }}
            </button>
        @else
            <select id="{{ $id }}" wire:change="selectSort('{{ $list }}', $event.target.value)" class="h-11 min-w-0 flex-1 rounded-sm border border-mono-200 bg-mono-white px-3 text-sm text-mono-900" data-sort-select>
                @foreach ($columns as $col => $label)<option value="{{ $col }}" @selected($sortBy === $col)>{{ $label }}</option>@endforeach
            </select>
            <button type="button" wire:click="toggleSortDir('{{ $list }}')" class="inline-flex h-11 min-w-[88px] items-center justify-center gap-1 rounded-sm border border-mono-200 bg-mono-white px-3 text-sm font-semibold text-mono-900" aria-label="Inverter ordem ({{ $sortDir === 'asc' ? 'crescente' : 'decrescente' }})" data-sort-dir="{{ $sortDir }}">
                <span class="material-icons-outlined text-[18px]" aria-hidden="true">{{ $sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>{{ $sortDir === 'asc' ? 'Cresc.' : 'Decresc.' }}
            </button>
        @endif
    </div>
@endif

@props([
    'column',
    'label',
    'sortBy' => null,
    'sortDir' => 'desc',
    'sorts' => null,
    'list' => null,
    'align' => 'left',
])
@php
    if ($list && is_array($sorts) && isset($sorts[$list]['by'])) {
        $sortBy = $sorts[$list]['by'];
        $sortDir = $sorts[$list]['dir'] ?? 'desc';
    }
    $active = $sortBy === $column;
    $alignClass = $align === 'right' ? 'text-right' : ($align === 'center' ? 'text-center' : 'text-left');
    $click = $list ? "sortByColumn('{$column}', '{$list}')" : "sortByColumn('{$column}')";
@endphp
<th {{ $attributes->class(['whitespace-nowrap', $alignClass]) }} aria-sort="{{ $active ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <button
        type="button"
        wire:click="{{ $click }}"
        class="inline-flex min-h-11 items-center gap-1 uppercase tracking-wider {{ $align === 'right' ? 'w-full justify-end' : ($align === 'center' ? 'w-full justify-center' : '') }} {{ $active ? 'font-semibold text-primary-500' : 'text-inherit hover:text-primary-500' }}"
        title="Ordenar por {{ mb_strtolower($label) }}"
        data-sort-col="{{ $column }}"
        @if ($list) data-sort-list="{{ $list }}" @endif
        @if ($active) data-sort-active="{{ $sortDir }}" @endif
    >
        {{ $label }}
        <span class="material-icons-outlined text-[16px] {{ $active ? '' : 'opacity-40' }}" aria-hidden="true">{{ $active ? ($sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward') : 'unfold_more' }}</span>
    </button>
</th>

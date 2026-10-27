@props(['options', 'selectedId' => null, 'model', 'id', 'label', 'placeholder' => 'Selecione', 'clearValue' => null, 'searchLabel' => null, 'searchPlaceholder' => 'Digite para pesquisar...', 'emptyMessage' => 'Nenhum resultado encontrado.', 'disabled' => false, 'placeholderIcon' => null])

@php
    $selectedOption = $options->firstWhere('id', $selectedId);
@endphp

<div wire:key="{{ $id }}-picker-{{ $options->count() }}" class="relative" x-data="{
    open: false,
    query: '',
    position: {},
    normalize(text) { return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR') },
    matches(name) { return this.normalize(name).includes(this.normalize(this.query.trim())) },
    hasMatches() { return [...(this.$refs.options?.querySelectorAll('[data-option-search]') || [])].some(item => this.matches(item.dataset.optionSearch)) },
    place() {
        const rect = this.$refs.trigger.getBoundingClientRect();
        const below = window.innerHeight - rect.bottom - 24;
        const above = rect.top - 24;
        const upwards = below < 280 && above > below;
        this.position = {
            left: rect.left + 'px', width: rect.width + 'px',
            maxHeight: Math.max(80, Math.min(320, upwards ? above : below)) + 'px',
            ...(upwards ? { bottom: (window.innerHeight - rect.top + 8) + 'px' } : { top: (rect.bottom + 8) + 'px' })
        };
    },
    show() {
        this.query = '';
        this.place();
        this.open = true;
        this.$nextTick(() => this.$refs.search.focus({ preventScroll: true }));
    },
    choose(value) {
        $wire.set(@js($model), value);
        this.open = false;
        this.$refs.trigger.focus({ preventScroll: true });
    },
    move(step) {
        const items = [...this.$refs.options.querySelectorAll('[role=option]')].filter(item => item.getClientRects().length);
        if (!items.length) return;
        const index = items.indexOf(document.activeElement);
        const next = index < 0 ? (step > 0 ? 0 : items.length - 1) : (index + step + items.length) % items.length;
        items[next].focus({ preventScroll: true });
        items[next].scrollIntoView({ block: 'nearest' });
    }
}" @click.outside="open = false" @resize.window="if (open) place()" @scroll.window.capture="if (open) place()"
    @keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); open = false; $refs.trigger.focus() }"
    @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <label for="{{ $id }}-trigger" class="mb-2 block text-sm font-medium text-mono-600">{{ $label }}</label>
    <button id="{{ $id }}-trigger" x-ref="trigger" type="button" role="combobox" aria-haspopup="listbox" aria-controls="{{ $id }}-options" :aria-expanded="open" @disabled($disabled)
        @click="open ? open = false : show()" @keydown.arrow-down.prevent="show()" @keydown.arrow-up.prevent="show()"
        class="flex h-12 w-full items-center gap-2 rounded-pill border border-mono-200 bg-mono-white px-4 text-left text-sm text-mono-900 transition-colors focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-100 disabled:cursor-not-allowed disabled:opacity-50">
        @if (! empty($selectedOption['icon']) || $placeholderIcon)
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full" style="color: {{ $selectedOption['color'] ?? 'var(--colors-mono-g600)' }}; background-color: {{ isset($selectedOption['color']) ? $selectedOption['color'].'1A' : 'var(--colors-mono-g100)' }}">
                <span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $selectedOption['icon'] ?? $placeholderIcon }}</span>
            </span>
        @endif
        <span class="min-w-0 flex-1 truncate">{{ $selectedOption['label'] ?? $placeholder }}</span>
        <span class="material-icons-outlined text-[20px] text-mono-400" aria-hidden="true">expand_more</span>
    </button>

    <div x-show="open" x-cloak :style="position" class="fixed z-50 flex flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
        <div class="shrink-0 border-b border-mono-100 p-3">
            <label for="{{ $id }}-search" class="sr-only">{{ $searchLabel ?? 'Pesquisar '.mb_strtolower($label) }}</label>
            <div class="flex items-center gap-2 rounded-pill border border-mono-200 px-3 focus-within:border-primary-500">
                <span class="material-icons-outlined text-[18px] text-mono-400" aria-hidden="true">search</span>
                <input id="{{ $id }}-search" x-ref="search" x-model="query" type="search" autocomplete="off" placeholder="{{ $searchPlaceholder }}"
                    @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                    @keydown.enter.prevent="[...$refs.options.querySelectorAll('[data-option-search]')].find(item => matches(item.dataset.optionSearch))?.click()"
                    class="h-10 min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-mono-900 placeholder:text-mono-300 focus:ring-0" />
            </div>
        </div>
        <div id="{{ $id }}-options" x-ref="options" role="listbox" aria-label="{{ $label }}" class="min-h-0 flex-1 overflow-y-auto p-2"
            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)">
            <button type="button" role="option" tabindex="-1" aria-selected="{{ $selectedOption ? 'false' : 'true' }}"
                x-show="query.trim() === ''" @click="choose(@js($clearValue))"
                class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-mono-600 hover:bg-mono-50 focus:bg-mono-50 focus:outline-none">
                @if ($placeholderIcon)<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-mono-50 text-mono-400"><span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $placeholderIcon }}</span></span>@endif
                <span class="flex-1">{{ $placeholder }}</span>
                @if (! $selectedOption)<span class="material-icons-outlined text-[18px] text-primary-500" aria-hidden="true">check</span>@endif
            </button>
            @foreach ($options as $option)
                <button wire:key="{{ $id }}-option-{{ $option['id'] }}" type="button" role="option" tabindex="-1" data-option-id="{{ $option['id'] }}" data-option-search="{{ $option['label'] }}" aria-label="{{ $option['label'] }}"
                    aria-selected="{{ $selectedId == $option['id'] ? 'true' : 'false' }}" x-show="matches($el.dataset.optionSearch)" @click="choose(@js($option['id']))"
                    class="flex w-full items-center gap-2 rounded-xl {{ ! empty($option['indent']) ? 'pl-7 pr-3' : 'px-3' }} py-2 text-left text-sm text-mono-900 hover:bg-mono-50 focus:bg-mono-50 focus:outline-none">
                    @if (! empty($option['icon']))
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="color: {{ $option['color'] ?? '#ff6f00' }}; background-color: {{ ($option['color'] ?? '#ff6f00').'1A' }}"><span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $option['icon'] }}</span></span>
                    @endif
                    <span class="min-w-0 flex-1 break-words">{{ $option['name'] ?? $option['label'] }}
                        @if (! empty($option['description']))<span class="block text-xs text-mono-400">{{ $option['description'] }}</span>@endif
                    </span>
                    @if ($selectedId == $option['id'])<span class="material-icons-outlined text-[18px] text-primary-500" aria-hidden="true">check</span>@endif
                </button>
            @endforeach
            <p x-show="!hasMatches()" role="status" class="px-3 py-4 text-sm text-mono-600">{{ $emptyMessage }}</p>
        </div>
    </div>
</div>

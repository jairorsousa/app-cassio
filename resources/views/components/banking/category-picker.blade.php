@props(['categories', 'disabled' => false])

@php
    $icons = [
        'Utensils' => 'restaurant', 'Repeat' => 'currency_exchange', 'GraduationCap' => 'school',
        'Handshake' => 'payments', 'Receipt' => 'receipt_long', 'Gamepad2' => 'sports_esports',
        'Home' => 'home', 'HeartPulse' => 'local_hospital', 'Car' => 'directions_car',
        'Shirt' => 'checkroom', 'PawPrint' => 'pets', 'Briefcase' => 'work',
        'TrendingUp' => 'trending_up', 'Wallet' => 'payments', 'Gift' => 'account_balance_wallet', 'Tag' => 'label',
    ];
    $options = $categories->map(fn ($category) => [
        'id' => $category->id,
        'name' => $category->name,
        'icon' => $icons[$category->icon] ?? ($category->icon ?: 'label'),
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $category->color ?? '') ? $category->color : '#ff6f00',
        'child' => $category->parent_id !== null,
    ])->values()->all();
@endphp

<div class="relative" x-data="{
    open: false,
    value: $wire.entangle('formCategoryId'),
    options: @js($options),
    get selected() { return this.options.find(option => option.id == this.value) },
    choose(id) { this.value = id; this.open = false; this.$refs.trigger.focus() },
    move(step) {
        const items = [...this.$refs.options.querySelectorAll('[role=option]')];
        const index = items.indexOf(document.activeElement);
        items[(index + step + items.length) % items.length].focus();
    },
    show() {
        this.open = true;
        this.$nextTick(() => {
            const items = [...this.$refs.options.querySelectorAll('[role=option]')];
            (items.find(item => item.getAttribute('aria-selected') === 'true') || items[0]).focus();
        });
    }
}" @click.outside="open = false" @keydown.escape.stop.prevent="open = false; $refs.trigger.focus()"
    @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <label class="mb-2 block text-sm font-medium text-mono-600" for="transaction-category-trigger">Categoria</label>
    <button id="transaction-category-trigger" type="button" x-ref="trigger" aria-haspopup="listbox"
        :aria-expanded="open" aria-controls="transaction-category-options" @disabled($disabled)
        @click="open ? open = false : show()" @keydown.arrow-down.prevent="show()" @keydown.arrow-up.prevent="show()"
        class="flex h-12 w-full items-center gap-3 rounded-pill border border-mono-200 bg-mono-white px-4 text-left text-sm text-mono-900 transition-colors focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-100 disabled:cursor-not-allowed disabled:opacity-50">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
            :style="{ color: selected?.color || '#9ca3af', backgroundColor: selected ? selected.color + '1A' : '#f3f4f6' }">
            <span class="material-icons-outlined text-[20px]" x-text="selected?.icon || 'label_off'" aria-hidden="true"></span>
        </span>
        <span class="min-w-0 flex-1 truncate" x-text="selected?.name || 'Sem categoria'"></span>
        <span class="material-icons-outlined text-[20px] text-mono-400" aria-hidden="true">expand_more</span>
    </button>

    <div id="transaction-category-options" x-ref="options" x-show="open" x-cloak x-transition
        role="listbox" aria-label="Categorias" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
        @keydown.home.prevent="$refs.options.firstElementChild.focus()" @keydown.end.prevent="$refs.options.lastElementChild.focus()"
        class="absolute left-0 right-0 top-full z-50 mt-2 max-h-64 overflow-y-auto rounded-2xl border border-mono-100 bg-mono-white p-2 shadow-elevated">
        <button type="button" role="option" tabindex="-1" data-category-id="" :aria-selected="value === null"
            @click="choose(null)" class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm text-mono-900 hover:bg-mono-50 focus:bg-mono-50 focus:outline-none">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-mono-50 text-mono-400"><span class="material-icons-outlined text-[20px]" aria-hidden="true">label_off</span></span>
            <span class="flex-1">Sem categoria</span>
            <span x-show="value === null" class="material-icons-outlined text-[18px] text-primary-500" aria-hidden="true">check</span>
        </button>
        @foreach ($options as $option)
            <button type="button" role="option" tabindex="-1" data-category-id="{{ $option['id'] }}" :aria-selected="value == {{ $option['id'] }}"
                @click="choose({{ $option['id'] }})" class="flex w-full items-center gap-3 rounded-xl py-2 pr-3 {{ $option['child'] ? 'pl-7' : 'pl-3' }} text-left text-sm text-mono-900 hover:bg-mono-50 focus:bg-mono-50 focus:outline-none">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="color: {{ $option['color'] }}; background-color: {{ $option['color'] }}1A">
                    <span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $option['icon'] }}</span>
                </span>
                <span class="min-w-0 flex-1 break-words">{{ $option['name'] }}</span>
                <span x-show="value == {{ $option['id'] }}" class="material-icons-outlined text-[18px] text-primary-500" aria-hidden="true">check</span>
            </button>
        @endforeach
    </div>
</div>

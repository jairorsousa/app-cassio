@props(['size' => 'default', 'align' => 'right'])

<div {{ $attributes->class(['relative inline-block']) }}
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.stop.prevent="open = false; $refs.trigger.focus()"
    @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <x-jr.button :size="$size" x-ref="trigger"
        @click="open = !open; if (open) $nextTick(() => $refs.options.querySelector('button').focus())"
        @keydown.arrow-down.prevent="open = true; $nextTick(() => $refs.options.querySelector('button').focus())"
        aria-haspopup="menu" x-bind:aria-expanded="open">
        <span class="material-icons-outlined text-[18px]" aria-hidden="true">add</span>
        Novo lançamento
        <span class="material-icons-outlined text-[18px]" aria-hidden="true">expand_more</span>
    </x-jr.button>

    <div x-ref="options" x-show="open" x-cloak x-transition.origin.top
        role="menu" aria-label="Tipo de lançamento"
        @keydown.arrow-down.prevent="const items = [...$refs.options.querySelectorAll('button')]; items[(items.indexOf(document.activeElement) + 1) % items.length].focus()"
        @keydown.arrow-up.prevent="const items = [...$refs.options.querySelectorAll('button')]; items[(items.indexOf(document.activeElement) + items.length - 1) % items.length].focus()"
        class="absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} top-full z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-2xl border border-mono-100 bg-mono-white p-2 shadow-elevated">
        @foreach ([
            ['type' => 'expense', 'label' => 'Despesa', 'icon' => 'trending_down', 'color' => 'text-red-500', 'background' => 'bg-red-50'],
            ['type' => 'income', 'label' => 'Receita', 'icon' => 'trending_up', 'color' => 'text-green-600', 'background' => 'bg-green-50'],
            ['type' => 'transfer', 'label' => 'Transferência', 'icon' => 'sync_alt', 'color' => 'text-blue-500', 'background' => 'bg-blue-50'],
        ] as $option)
            <button type="button" role="menuitem" wire:click="create('{{ $option['type'] }}')"
                @click="open = false; $refs.trigger.focus()"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-mono-900 transition-colors hover:bg-mono-50 focus:bg-mono-50 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $option['background'] }} {{ $option['color'] }}">
                    <span class="material-icons-outlined text-[22px]" aria-hidden="true">{{ $option['icon'] }}</span>
                </span>
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>
</div>

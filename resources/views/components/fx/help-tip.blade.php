{{-- Ícone "?" com uma dica curta. Abre no toque (celular), no clique e ao passar o mouse; fecha com Esc ou clique fora. --}}
{{-- A dica abre logo abaixo do elemento pai com "relative" (a linha do rótulo), na largura dele, para não sair da tela no celular. --}}
@props(['text', 'label' => 'O que é isto?'])
<span class="inline-flex shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" @pointerenter="open = $event.pointerType === 'mouse' ? true : open" @pointerleave="open = $event.pointerType === 'mouse' ? false : open">
    <button type="button" @click.prevent="open = true" :aria-expanded="open.toString()" aria-label="{{ $label }}" data-help-tip
        class="inline-flex h-7 w-7 items-center justify-center rounded-full text-mono-400 transition-colors hover:text-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-300">
        <span class="material-icons-outlined text-[18px]" aria-hidden="true">help_outline</span>
    </button>
    <span x-cloak x-show="open" x-transition.opacity role="tooltip"
        class="absolute left-0 right-0 top-full z-30 mt-1 max-w-sm rounded-lg border border-mono-200 bg-mono-white p-3 text-left text-xs font-normal normal-case leading-snug tracking-normal text-mono-700 shadow-lg">{{ $text }}</span>
</span>

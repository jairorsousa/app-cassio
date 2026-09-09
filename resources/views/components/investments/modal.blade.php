@props(['title', 'submit' => 'save', 'cancel' => 'cancel'])
<div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" x-data x-on:keydown.escape.window="$wire.{{ $cancel }}()">
    <button type="button" class="fixed inset-0 bg-black/45" wire:click="{{ $cancel }}" aria-label="Fechar modal"></button>
    <div role="dialog" aria-modal="true" aria-label="{{ $title }}" class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated" x-trap.inert.noscroll="true">
        <div class="flex shrink-0 items-center justify-between border-b border-mono-100 px-6 py-5">
            <h3 class="text-lg font-bold text-mono-900">{{ $title }}</h3>
            <button type="button" wire:click="{{ $cancel }}" class="text-mono-600" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
        </div>
        <form wire:submit="{{ $submit }}" class="flex min-h-0 flex-col">
            <div class="overflow-y-auto p-6"><div class="grid grid-cols-1 gap-5 md:grid-cols-2">{{ $slot }}</div></div>
            <div class="flex shrink-0 justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                <x-jr.button type="button" variant="mono" wire:click="{{ $cancel }}">Cancelar</x-jr.button>
                <x-jr.button type="submit" wire:loading.attr="disabled"><span class="material-icons-outlined text-[18px]">check</span>Salvar</x-jr.button>
            </div>
        </form>
    </div>
</div>

@props(['invitation' => null, 'name' => '', 'action' => null, 'label' => 'Gerar link'])
{{-- Link atual do convite (só quando o convite está válido), em letra pequena, com botão de copiar. --}}
@php($link = $invitation?->currentLink())
<div class="mt-1 flex flex-wrap items-center gap-2 text-xs" data-invite-link-row x-data="{ copied: false }">
    @if ($link)
        <code class="max-w-full break-all text-mono-600" data-current-link>{{ $link }}</code>
        <button type="button" class="rounded-lg border border-mono-200 px-2 py-0.5" data-copy-link
            x-on:click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 2500)">Copiar</button>
        <span x-show="copied" x-cloak class="text-system-up" data-copied-msg>Link copiado de {{ $name }}</span>
    @elseif ($invitation && $invitation->status() === 'pending')
        <span class="text-mono-500" data-no-link>Gere um novo link</span>
        @if ($action)<button type="button" wire:click="{{ $action }}" class="rounded-lg border border-mono-200 px-2 py-0.5" data-generate-link>{{ $label }}</button>@endif
    @else
        <span class="text-mono-500" data-no-link>Sem link gerado</span>
        @if ($action)<button type="button" wire:click="{{ $action }}" class="rounded-lg border border-mono-200 px-2 py-0.5" data-generate-link>{{ $label }}</button>@endif
    @endif
</div>

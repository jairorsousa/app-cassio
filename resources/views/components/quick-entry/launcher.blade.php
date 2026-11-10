@props(['variant' => 'header'])
@php
    $qeUser = auth()->user();
    $qeAction = \App\Support\QuickEntry::moduleAction(request()->route()?->getName(), $qeUser);
@endphp
@if (\App\Support\QuickEntry::enabled($qeUser))
    @if ($variant === 'fab' && request()->routeIs('divisions.show'))
        {{-- Divisões já tem o próprio botão flutuante (Adicionar despesa) no mesmo canto. --}}
    @elseif ($variant === 'fab')
        {{-- Celular: botão "+" redondo no canto inferior direito, respeitando a área segura do iPhone. --}}
        <div class="qe-fab" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-quick-fab>
            @if ($qeAction)
                <div x-show="open" x-cloak x-transition class="qe-fab-menu" role="menu" style="display:none">
                    <button type="button" role="menuitem" class="qe-menu-item" x-on:click="open = false; Livewire.dispatch('quick-entry', { type: 'expense' })" data-quick-open>
                        <span class="material-icons-outlined text-[20px]" aria-hidden="true">receipt_long</span>Novo lançamento
                    </button>
                    <a href="{{ $qeAction['href'] }}" role="menuitem" class="qe-menu-item" data-quick-module-action>
                        <span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $qeAction['icon'] }}</span>{{ $qeAction['label'] }}
                    </a>
                </div>
                <button type="button" class="qe-fab-btn" x-on:click="open = !open" :aria-expanded="open" aria-label="Novo: lançamento ou {{ mb_strtolower($qeAction['label']) }}">
                    <span class="material-icons-outlined" style="font-size:30px" aria-hidden="true" x-text="open ? 'close' : 'add'">add</span>
                </button>
            @else
                <button type="button" class="qe-fab-btn" x-on:click="Livewire.dispatch('quick-entry', { type: 'expense' })" aria-label="Novo lançamento" data-quick-open>
                    <span class="material-icons-outlined" style="font-size:30px" aria-hidden="true">add</span>
                </button>
            @endif
        </div>
    @else
        {{-- Desktop: "+ Lançar" fixo na barra superior; em módulos com ação própria vira botão dividido. --}}
        <div class="qe-desktop relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-quick-launch>
            <button type="button" class="qe-launch-btn {{ $qeAction ? 'qe-launch-split' : '' }}" x-on:click="Livewire.dispatch('quick-entry', { type: 'expense' })" title="Novo lançamento (atalho: n d)" data-quick-open>
                <span class="material-icons-outlined text-[18px]" aria-hidden="true">add</span>Lançar
            </button>
            @if ($qeAction)
                <button type="button" class="qe-launch-btn qe-launch-caret" x-on:click="open = !open" :aria-expanded="open" aria-label="Mais ações">
                    <span class="material-icons-outlined text-[18px]" aria-hidden="true">expand_more</span>
                </button>
                <div x-show="open" x-cloak x-transition class="qe-launch-menu" role="menu" style="display:none">
                    <button type="button" role="menuitem" class="qe-menu-item" x-on:click="open = false; Livewire.dispatch('quick-entry', { type: 'expense' })">
                        <span class="material-icons-outlined text-[20px]" aria-hidden="true">receipt_long</span>Novo lançamento
                    </button>
                    <a href="{{ $qeAction['href'] }}" role="menuitem" class="qe-menu-item" data-quick-module-action>
                        <span class="material-icons-outlined text-[20px]" aria-hidden="true">{{ $qeAction['icon'] }}</span>{{ $qeAction['label'] }}
                    </a>
                </div>
            @endif
        </div>
    @endif
@endif

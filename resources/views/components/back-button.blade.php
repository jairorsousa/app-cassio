{{-- Botão "Voltar" padrão do sistema (celular e computador): seta + texto, área de toque de 44px, no topo da tela.
     - href: a tela-mãe lógica (destino garantido, inclusive quando a página foi aberta direto por link).
       Se a tela anterior do navegador for exatamente a tela-mãe, volta pelo histórico para manter filtros e rolagem.
     - action: em vez de navegar, chama um método Livewire (ex.: fechar o detalhe e voltar à lista). --}}
@props(['href' => null, 'label' => 'Voltar', 'action' => null])
@php
    $classes = 'inline-flex h-11 min-h-[44px] w-fit shrink-0 items-center gap-2 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm font-semibold text-mono-700 shadow-sm transition-colors hover:border-primary-300 hover:text-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500';
    $href ??= url('/dashboard');
@endphp
@if ($action)
    <button type="button" wire:click="{{ $action }}" {{ $attributes->merge(['class' => $classes]) }} data-back-button>
        <span class="material-icons-outlined text-[20px]" aria-hidden="true">arrow_back</span>{{ $label }}
    </button>
@else
    <a href="{{ $href }}"
        x-data="{ back(e) { try { const r = document.referrer ? new URL(document.referrer) : null; const t = new URL(this.$el.href); if (r && r.origin === location.origin && r.pathname === t.pathname && r.pathname !== location.pathname && history.length > 1) { e.preventDefault(); history.back(); } } catch (err) {} } }"
        @click="back($event)"
        {{ $attributes->merge(['class' => $classes]) }} data-back-button>
        <span class="material-icons-outlined text-[20px]" aria-hidden="true">arrow_back</span>{{ $label }}
    </a>
@endif

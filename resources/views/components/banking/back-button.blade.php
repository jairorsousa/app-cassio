{{-- Botão "Voltar" claro (celular e computador). Com action: chama o método Livewire (ex.: fechar o detalhe e voltar à lista).
     Sem action: volta para a tela anterior do sistema; se não houver (link aberto direto), vai para o fallback. --}}
@props(['fallback' => null, 'label' => 'Voltar', 'action' => null])
@php
    $classes = 'inline-flex h-11 w-fit items-center gap-2 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm font-semibold text-mono-700 transition-colors hover:border-primary-300 hover:text-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500';
    $fallback ??= route('banking.dashboard');
@endphp
@if ($action)
    <button type="button" wire:click="{{ $action }}" {{ $attributes->merge(['class' => $classes]) }} data-back-button>
@else
    <button type="button" x-data
        @click="(document.referrer && new URL(document.referrer).origin === location.origin && new URL(document.referrer).pathname !== location.pathname && history.length > 1) ? history.back() : (location.href = @js($fallback))"
        {{ $attributes->merge(['class' => $classes]) }} data-back-button>
@endif
    <span class="material-icons-outlined text-[20px]" aria-hidden="true">arrow_back</span>{{ $label }}
</button>

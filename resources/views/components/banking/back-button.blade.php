{{-- Mantido por compatibilidade: use <x-back-button>. --}}
@props(['fallback' => null, 'label' => 'Voltar', 'action' => null])
<x-back-button :href="$fallback ?? route('banking.dashboard')" :label="$label" :action="$action" />

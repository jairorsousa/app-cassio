{{-- Barra "pago X de Y, falta Z" de um bem com pagamentos. $s = Property::paymentSummary() --}}
@php $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'); @endphp
<div class="mt-2" data-payment-bar>
    <div class="h-2 w-full overflow-hidden rounded-full bg-cryptex-bg-tertiary"><div class="h-full rounded-full bg-cryptex-green-500" style="width: {{ $s['percent'] }}%"></div></div>
    <div class="mt-1 text-xs text-cryptex-text-secondary">
        Pago <strong class="text-cryptex-text-primary">{{ $brl($s['paid']) }}</strong>@if ($s['total'] > 0) de {{ $brl($s['total']) }} · falta <strong class="text-cryptex-text-primary">{{ $brl($s['remaining']) }}</strong>@endif
        @if ($s['planned'] > 0)<span class="block">Previsto: {{ $brl($s['planned']) }}</span>@endif
        @if ($s['over'] > 0)<span class="block text-cryptex-red-500">Pago acima do total em {{ $brl($s['over']) }}</span>@endif
    </div>
</div>

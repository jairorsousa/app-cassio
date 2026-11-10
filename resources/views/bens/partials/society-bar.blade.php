{{-- Barra de um bem comprado em sociedade: total pago pelo grupo × valor total, e a parte do Cassio. --}}
@php $s = $p->societySummary(); $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'); @endphp
<div class="mt-2" data-society-bar>
    @if ($s['total'] !== null)
        <div class="h-2 w-full overflow-hidden rounded-full bg-cryptex-bg-tertiary"><div class="h-full rounded-full bg-cryptex-green-500" style="width: {{ $s['percent_paid'] }}%"></div></div>
    @endif
    <div class="mt-1 text-xs text-cryptex-text-secondary">
        Grupo pagou <strong class="text-cryptex-text-primary">{{ $brl($s['paid']) }}</strong>@if ($s['total'] !== null) de {{ $brl($s['total']) }} · falta <strong class="text-cryptex-text-primary">{{ $brl($s['remaining']) }}</strong>@endif
        <span class="block">Sua parte: <strong class="text-cryptex-text-primary">{{ $s['owner_percent'] !== null ? number_format($s['owner_percent'] * 100, 2, ',', '.').'%' : '—' }}</strong> · {{ $brl($s['owner_part']) }}</span>
        @if ($s['total'] === null)<span class="block text-cryptex-brand-400" data-total-missing>Informe o valor total</span>@endif
    </div>
</div>

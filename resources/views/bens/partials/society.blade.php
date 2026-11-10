{{-- Bem comprado em sociedade ($p): partes dos sócios pelo que cada um assumiu das despesas "Compra do bem" do grupo. --}}
@php
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $s = $p->societySummary();
    $pct = fn ($v) => $v === null ? '—' : number_format($v, 2, ',', '.').'%';
@endphp
<x-fx.card data-payments="{{ $p->id }}" data-society="{{ $p->id }}">
    <div x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })" class="flex items-start gap-space-3">
        <div class="min-w-0">
            <div class="text-fs-12 uppercase text-cryptex-text-tertiary">Compra em sociedade</div>
            <h3 class="text-fs-16 font-semibold break-words">{{ $p->description }}</h3>
            <div class="text-xs text-cryptex-text-secondary">Grupo do Divisões: <a class="text-cryptex-brand-400" href="{{ route('divisions.show', $p->division_group_id) }}">{{ $s['group']?->name ?? '#'.$p->division_group_id }}</a></div>
        </div>
        <button type="button" wire:click="closePayments" class="ml-auto min-h-[40px] px-2 text-cryptex-text-secondary" aria-label="Fechar">✕</button>
    </div>

    <div class="mt-space-3 rounded-sm border border-cryptex-border-default p-3">
        <div class="flex items-baseline justify-between gap-2 text-sm"><span class="text-cryptex-text-secondary">Valor total do bem</span><strong class="font-mono">{{ $s['total'] !== null ? $brl($s['total']) : '—' }}</strong></div>
        @if ($s['total'] === null)
            <p class="mt-1 rounded-sm bg-[rgba(240,185,11,0.1)] p-2 text-xs text-cryptex-brand-400" data-total-missing>Informe o valor total (em Editar) para ver quanto falta pagar.</p>
        @else
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-cryptex-bg-tertiary"><div class="h-full rounded-full bg-cryptex-green-500" style="width: {{ $s['percent_paid'] }}%"></div></div>
        @endif
        <div class="mt-1 text-xs text-cryptex-text-secondary">Pago pelo grupo <strong class="text-cryptex-text-primary">{{ $brl($s['paid']) }}</strong>@if ($s['total'] !== null) de {{ $brl($s['total']) }} · falta <strong class="text-cryptex-text-primary">{{ $brl($s['remaining']) }}</strong>@endif</div>
        <div class="mt-1 text-xs text-cryptex-text-secondary">No seu patrimônio: <strong class="text-cryptex-text-primary">{{ $brl($p->patrimonyValue()) }}</strong>{{ ($p->current_value ?? $p->acquisition_value) === null ? ' (o que você já assumiu da compra, até informar o valor)' : ' (valor × sua parte)' }}</div>
    </div>

    <h4 class="mt-space-4 text-sm font-semibold">Parte de cada sócio</h4>
    <div class="mt-1 divide-y divide-cryptex-border-default text-sm" data-society-members>
        @foreach ($s['members'] as $m)
            <div class="flex items-start justify-between gap-2 py-2" data-society-member="{{ $m['id'] }}">
                <div class="min-w-0"><span class="font-semibold">{{ $m['name'] }}</span>@if ($m['is_owner']) <x-fx.badge variant="info">você</x-fx.badge>@endif
                    <div class="text-xs text-cryptex-text-tertiary">Pagou do bolso {{ $brl($m['cash']) }}</div></div>
                <div class="text-right"><strong class="font-mono">{{ $pct($m['percent']) }}</strong><div class="text-xs text-cryptex-text-secondary">parte {{ $brl($m['part']) }}</div></div>
            </div>
        @endforeach
    </div>
    <p class="mt-1 text-xs text-cryptex-text-tertiary">A parte de cada um segue o que assumiu nas parcelas marcadas como "Compra do bem". Quem paga a mais escolhe na parcela: adiantamento aos sócios (vira crédito no Divisões) ou aumentar a própria parte.</p>

    <h4 class="mt-space-4 text-sm font-semibold">Parcelas da compra</h4>
    <div class="mt-1 divide-y divide-cryptex-border-default">
        @forelse ($s['expenses'] as $e)
            <div class="py-2 text-sm" data-society-expense="{{ $e->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0"><div class="font-semibold break-words">{{ $e->description }}</div>
                        <div class="text-xs text-cryptex-text-secondary">{{ $e->date->format('d/m/Y') }} · pago por {{ $e->payer?->name }} · {{ \App\Domains\Divisions\Models\DivisionExpense::PURCHASE_MODES[$e->purchase_mode ?? 'advance'] }}</div></div>
                    <strong class="font-mono whitespace-nowrap">{{ $brl($e->amount) }}</strong>
                </div>
            </div>
        @empty
            <p class="py-4 text-center text-sm text-cryptex-text-tertiary">Nenhuma parcela marcada como "Compra do bem" no grupo.</p>
        @endforelse
    </div>

    <button type="button" wire:click="newPayment" class="mt-space-3 min-h-[44px] w-full rounded-sm border border-dashed border-cryptex-brand-400 font-semibold text-cryptex-brand-400" data-new-payment>+ Pagamento (no Divisões)</button>
    <p class="mt-1 text-center text-xs text-cryptex-text-tertiary">Abre a despesa do Divisões já marcada como "Compra do bem": um lançamento só na conta.</p>
</x-fx.card>

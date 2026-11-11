{{-- Seção Pagamentos de um bem ($p, com payments carregados). Pensada primeiro para o celular. --}}
@php
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $s = $p->paymentSummary();
    $in = 'h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 w-full';
@endphp
<x-fx.card data-payments="{{ $p->id }}">
    <div class="flex items-start gap-space-3">
        <div class="min-w-0">
            <div class="text-fs-12 uppercase text-cryptex-text-tertiary">{{ $p->financings->isNotEmpty() || $p->downPayments->isNotEmpty() ? 'Outros pagamentos' : 'Pagamentos' }}</div>
            <h3 class="text-fs-16 font-semibold break-words">{{ $p->description }}</h3>
        </div>
        <button type="button" wire:click="closePayments" class="ml-auto min-h-[40px] px-2 text-cryptex-text-secondary" aria-label="Fechar">✕</button>
    </div>

    <div class="mt-space-3 rounded-sm border border-cryptex-border-default p-3">
        <div class="flex items-baseline justify-between gap-2 text-sm"><span class="text-cryptex-text-secondary">Valor total da compra</span><strong class="font-mono">{{ $p->acquisition_value !== null ? $brl($p->acquisition_value) : '—' }}</strong></div>
        @include('bens.partials.bar', ['s' => $s])
        @if ($p->acquisition_value === null)
            <p class="mt-1 text-xs text-cryptex-text-tertiary">Informe o valor total da compra em Editar para ver quanto falta.</p>
        @endif
    </div>

    @error('payForm')<x-fx.alert variant="error" class="mt-space-3">{{ $message }}</x-fx.alert>@enderror

    <div class="mt-space-3 divide-y divide-cryptex-border-default">
        @forelse ($p->payments as $i => $pay)
            @php
                $tx = $pay->transactions;
                $link = null;
                if ($tx->isNotEmpty()) {
                    $first = $tx->min('date'); $last = $tx->max('date');
                    $link = route('banking.transactions.index', ['account' => $pay->method === 'card' ? 'card:'.$tx->first()->credit_card_id : $pay->bank_account_id, 'from' => \Illuminate\Support\Carbon::parse($first)->toDateString(), 'to' => \Illuminate\Support\Carbon::parse($last)->toDateString()]);
                }
            @endphp
            <div class="py-3 text-sm" data-payment="{{ $pay->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold">Pagamento {{ $i + 1 }} @if ($pay->isPlanned())<x-fx.badge variant="warning">Previsto</x-fx.badge>@else<x-fx.badge variant="success">Pago</x-fx.badge>@endif</div>
                        <div class="text-xs text-cryptex-text-secondary">{{ $pay->date ? $pay->date->format('d/m/Y') : 'Sem data' }} · {{ $pay->sourceLabel() }}</div>
                        <div class="text-xs text-cryptex-text-tertiary">{{ $pay->category?->name ?? 'Sem categoria' }}@if ($pay->notes) · {{ $pay->notes }}@endif</div>
                    </div>
                    <strong class="font-mono whitespace-nowrap">{{ $brl($pay->amount) }}</strong>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                    @if ($pay->isPlanned())
                        <button type="button" wire:click="markPaidForm({{ $pay->id }})" class="min-h-[36px] rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white">Paguei</button>
                    @elseif ($link)
                        <a href="{{ $link }}" class="text-cryptex-brand-400">{{ $pay->method === 'card' ? 'Ver na fatura ('.$tx->count().' '.($tx->count() > 1 ? 'parcelas' : 'parcela').')' : 'Ver no extrato' }}</a>
                    @else
                        <span class="text-cryptex-red-500">Lançamento não encontrado</span>
                    @endif
                    <button type="button" wire:click="deletePayment({{ $pay->id }})" wire:confirm="{{ $pay->isPlanned() ? 'Apagar este pagamento previsto?' : 'Apagar este pagamento? O lançamento ligado a ele (no extrato ou na fatura) também será apagado.' }}" class="ml-auto min-h-[36px] text-cryptex-red-500">Apagar</button>
                </div>
            </div>
        @empty
            <p class="py-4 text-center text-sm text-cryptex-text-tertiary">Nenhum pagamento registrado.</p>
        @endforelse
    </div>

    @if (! $showPayForm)
        <button type="button" wire:click="newPayment" class="mt-space-3 min-h-[44px] w-full rounded-sm border border-dashed border-cryptex-brand-400 font-semibold text-cryptex-brand-400" data-new-payment>+ Pagamento</button>
    @else
        <form wire:submit="savePayment" class="mt-space-3 grid gap-space-3 rounded-sm border border-cryptex-border-default p-3 text-sm md:grid-cols-2" data-pay-form>
            <h4 class="font-semibold md:col-span-2">{{ $payMarkId ? 'Paguei — escolha como pagou' : 'Novo pagamento' }}</h4>
            @if (! $payMarkId)
                <div class="flex gap-2 md:col-span-2" role="radiogroup">
                    @foreach (['paid' => 'Já paguei', 'planned' => 'Previsto'] as $k => $l)
                        <label class="flex min-h-[44px] flex-1 cursor-pointer items-center justify-center rounded-sm border {{ $payStatus === $k ? 'border-cryptex-brand-400 bg-cryptex-bg-elevated font-semibold' : 'border-cryptex-border-default' }}"><input type="radio" class="sr-only" wire:model.live="payStatus" value="{{ $k }}">{{ $l }}</label>
                    @endforeach
                </div>
            @endif
            <label class="flex flex-col gap-1">Valor<input type="text" inputmode="numeric" x-money wire:model="payAmount" class="{{ $in }}" placeholder="R$ 0,00" data-pay-amount /></label>
            <label class="flex flex-col gap-1">{{ $payStatus === 'planned' && ! $payMarkId ? 'Data prevista (opcional)' : 'Data do pagamento' }}<input type="date" wire:model="payDate" class="{{ $in }}" /></label>
            <div class="flex flex-col gap-1 md:col-span-2">
                <span>{{ $payStatus === 'planned' && ! $payMarkId ? 'Como vai pagar (opcional)' : 'Como pagou' }}</span>
                <div class="flex gap-2">
                    @foreach (['account' => 'Conta', 'card' => 'Cartão'] + ($payStatus === 'planned' && ! $payMarkId ? ['' => 'A definir'] : []) as $k => $l)
                        <label class="flex min-h-[44px] flex-1 cursor-pointer items-center justify-center rounded-sm border {{ $payMethod === (string) $k ? 'border-cryptex-brand-400 bg-cryptex-bg-elevated font-semibold' : 'border-cryptex-border-default' }}"><input type="radio" class="sr-only" wire:model.live="payMethod" value="{{ $k }}">{{ $l }}</label>
                    @endforeach
                </div>
            </div>
            @if ($payMethod === 'account')
                <label class="flex flex-col gap-1 md:col-span-2">Conta de onde saiu o dinheiro
                    <select wire:model="payAccount" class="{{ $in }}"><option value="">{{ $payStatus === 'planned' && ! $payMarkId ? 'A definir' : 'Escolha a conta' }}</option>@foreach ($payAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                </label>
            @elseif ($payMethod === 'card')
                <label class="flex flex-col gap-1">Cartão
                    <select wire:model="payCard" class="{{ $in }}"><option value="">{{ $payStatus === 'planned' && ! $payMarkId ? 'A definir' : 'Escolha o cartão' }}</option>@foreach ($payCards as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                </label>
                <label class="flex flex-col gap-1">Parcelas
                    <select wire:model="payInstallments" class="{{ $in }}">@for ($n = 1; $n <= 24; $n++)<option value="{{ $n }}">{{ $n === 1 ? 'À vista' : $n.'x' }}</option>@endfor</select>
                </label>
            @endif
            <label class="flex flex-col gap-1">Categoria
                <select wire:model="payCategory" class="{{ $in }}">@foreach ($payCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                <span class="text-xs text-cryptex-text-tertiary">"Aquisição de bem" sai da conta (ou entra na fatura), mas fica fora do resultado e dos gráficos de despesas.</span>
            </label>
            <label class="flex flex-col gap-1">Observação (opcional)<input type="text" wire:model="payNotes" maxlength="500" class="{{ $in }}" placeholder="Ex.: Entrada" /></label>
            @if ($payWarnings)
                <div class="rounded-sm border border-[rgba(240,185,11,0.4)] bg-[rgba(240,185,11,0.1)] p-3 text-cryptex-brand-400 md:col-span-2" data-pay-warnings>
                    @foreach ($payWarnings as $w)<p class="mb-1">{{ $w }}</p>@endforeach
                    <button type="button" wire:click="savePayment(true)" class="mt-1 min-h-[40px] rounded-sm border border-cryptex-brand-400 px-3 font-semibold">Salvar mesmo assim</button>
                </div>
            @endif
            <div class="flex gap-space-3 md:col-span-2">
                <button type="submit" class="min-h-[44px] flex-1 rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white md:flex-none">{{ $payMarkId ? 'Confirmar pagamento' : 'Salvar pagamento' }}</button>
                <button type="button" wire:click="$set('showPayForm', false)" class="min-h-[44px] rounded-sm border border-cryptex-border-default px-3">Cancelar</button>
            </div>
        </form>
    @endif
</x-fx.card>

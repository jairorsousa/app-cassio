{{-- Valor de lançamento colorido pelo tipo: despesa vermelho, receita verde, transferência cor padrão. Uso: <x-fx.amount :type="$t->type" :amount="$t->amount" /> --}}
@props(['type' => null, 'amount' => 0, 'signed' => false])
<span {{ $attributes->merge(['class' => \App\Support\AmountTone::cssClass($type, $amount).' whitespace-nowrap', 'data-amount-tone' => \App\Support\AmountTone::for($type, $amount)]) }}>{{ $slot->isEmpty() ? \App\Support\AmountTone::money($amount, ! $signed) : $slot }}</span>

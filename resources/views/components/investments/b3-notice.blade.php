@props(['what' => 'positions'])
@php
    $hasB3 = \App\Domains\Investments\Models\B3PositionSnapshot::latestConfirmed()->isNotEmpty();
    $income = $what === 'income' ? \App\Domains\Investments\Models\AssetIncomeEvent::count() : 0;
@endphp
@if (($what === 'income' && $income > 0) || ($what !== 'income' && $hasB3))
    <div class="mt-4 rounded-sm border border-cryptex-border-default p-3 text-sm" data-b3-notice="{{ $what }}">
        @if ($what === 'income')
            {{ $income }} provento(s) importado(s) da B3 e de extratos estão em <a class="font-semibold underline" href="{{ route('investments.b3') }}">Posição B3</a>. Esta lista mostra só os lançados manualmente (que geram entrada no Financeiro).
        @else
            A carteira atual vem da <a class="font-semibold underline" href="{{ route('investments.b3') }}">Posição B3</a>. Esta tela mostra só compras e vendas registradas; ainda não há notas de corretagem importadas.
        @endif
    </div>
@endif

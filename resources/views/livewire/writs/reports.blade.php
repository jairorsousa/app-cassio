<?php

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public function with(WritProfitabilityCalculator $calc): array
    {
        $finalized = Writ::where('stage', 'finalized')
            ->whereNotNull('actual_receipt_amount')
            ->orderByDesc('finalized_at')
            ->get();

        $rows = $finalized->map(function (Writ $w) use ($calc) {
            $p = $calc->realized($w);
            return [
                'writ' => $w,
                'profitability' => $p,
            ];
        });

        $totalInvested = $finalized->sum(fn (Writ $writ) => $writ->totalCost());
        $totalReceived = $finalized->sum('actual_receipt_amount');
        $totalProfit = $totalReceived - $totalInvested;
        $avgPct = $finalized->count() > 0
            ? $rows->avg(fn ($r) => $r['profitability']['profit_percentage'])
            : 0;
        $avgMonthly = $rows->whereNotNull('profitability.monthly_rate')->avg(fn ($r) => $r['profitability']['monthly_rate']);

        return compact('rows', 'totalInvested', 'totalReceived', 'totalProfit', 'avgPct', 'avgMonthly');
    }
}; ?>

<x-slot name="header">Requisitórios · Relatórios</x-slot>

<div class="flex flex-col gap-md">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Total investido</div>
            <div class="text-xl font-bold">R$ {{ number_format($totalInvested, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Total recebido</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($totalReceived, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Lucro líquido</div>
            <div class="text-xl font-bold {{ $totalProfit >= 0 ? 'text-system-up' : 'text-system-down' }}">
                R$ {{ number_format($totalProfit, 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">% médio / % a.m.</div>
            <div class="text-xl font-bold">{{ number_format((float) $avgPct, 2, ',', '.') }}%</div>
            <div class="text-xxs text-mono-600">{{ $avgMonthly !== null ? number_format((float) $avgMonthly, 3, ',', '.').'% a.m.' : '—' }}</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Operações encerradas</h3>
        @if ($rows->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma operação encerrada ainda.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Processo</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Pago</th>
                        <th class="text-right">Recebido</th>
                        <th class="text-right">Lucro</th>
                        <th class="text-right">%</th>
                        <th class="text-right">Dias</th>
                        <th class="text-right">% a.m.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $w = $row['writ']; $p = $row['profitability']; @endphp
                        <tr>
                            <td><a href="{{ route('writs.show', $w) }}" class="hover:text-primary-500">{{ $w->process_number ?: '#'.$w->id }}</a></td>
                            <td>{{ $w->type === 'rpv' ? 'RPV' : 'Precat.' }}</td>
                            <td class="text-right">R$ {{ number_format($w->totalCost(), 2, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format($w->actual_receipt_amount, 2, ',', '.') }}</td>
                            <td class="text-right font-semibold {{ $p['profit_amount'] >= 0 ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($p['profit_amount'], 2, ',', '.') }}
                            </td>
                            <td class="text-right">{{ number_format($p['profit_percentage'], 2, ',', '.') }}%</td>
                            <td class="text-right">{{ $p['days_elapsed'] ?? '—' }}</td>
                            <td class="text-right">{{ $p['monthly_rate'] !== null ? number_format($p['monthly_rate'], 3, ',', '.').'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>
</div>

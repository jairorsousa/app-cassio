<?php

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use App\Domains\Writs\Services\WritReceiptTimeReport;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public function with(WritProfitabilityCalculator $calc, WritReceiptTimeReport $times): array
    {
        $timeRows = $times->rows();
        $timeSummary = $times->summary();
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

        return compact('rows', 'totalInvested', 'totalReceived', 'totalProfit', 'avgPct', 'avgMonthly', 'timeRows', 'timeSummary');
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

    <x-fx.card data-receipt-times>
        <h3 class="text-md font-semibold mb-sm">Tempo de recebimento (compra → recebimento)</h3>
        <p class="mb-sm text-xs text-mono-600">Data da compra (pagamento da cessão) até a data real de recebimento, só dos requisitórios finalizados. Comparado com o prazo estimado cadastrado.</p>
        @if (empty($timeRows))
            <div class="text-sm text-mono-600">Nenhum requisitório finalizado com data de compra e de recebimento.</div>
        @else
            @foreach (['by_type' => 'Por tipo', 'by_court' => 'Por tribunal e tipo'] as $key => $title)
                <h4 class="mt-sm mb-xs text-sm font-semibold">{{ $title }}</h4>
                <table class="fx-table w-full text-sm mb-sm">
                    <thead><tr><th class="text-left">Grupo</th><th class="text-right">Qtde</th><th class="text-right">Média real</th><th class="text-right">Mín–máx</th><th class="text-right">Média estimada</th><th class="text-right">Diferença</th></tr></thead>
                    <tbody>
                        @foreach ($timeSummary[$key] as $s)
                            <tr>
                                <td>{{ $s['label'] }}</td>
                                <td class="text-right">{{ $s['count'] }}</td>
                                <td class="text-right">{{ number_format($s['avg_months'], 1, ',', '.') }} meses ({{ $s['avg_days'] }} dias)</td>
                                <td class="text-right">{{ number_format($s['min_months'], 1, ',', '.') }}–{{ number_format($s['max_months'], 1, ',', '.') }}</td>
                                <td class="text-right">{{ $s['avg_estimated'] !== null ? number_format($s['avg_estimated'], 1, ',', '.').' meses' : '—' }}</td>
                                <td class="text-right {{ $s['avg_estimated'] !== null && $s['avg_months'] > $s['avg_estimated'] ? 'text-system-down' : 'text-system-up' }}">{{ $s['avg_estimated'] !== null ? number_format($s['avg_months'] - $s['avg_estimated'], 1, ',', '.').' meses' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
            <h4 class="mt-sm mb-xs text-sm font-semibold">Por requisitório</h4>
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Processo</th><th class="text-left">Tipo</th><th class="text-left">Tribunal</th><th class="text-right">Compra</th><th class="text-right">Recebimento</th><th class="text-right">Real</th><th class="text-right">Estimado</th></tr></thead>
                <tbody>
                    @foreach ($timeRows as $r)
                        <tr>
                            <td><a href="{{ route('writs.show', $r['writ']) }}" class="hover:text-primary-500">{{ $r['writ']->process_number ?: '#'.$r['writ']->id }}</a></td>
                            <td>{{ $r['writ']->type === 'rpv' ? 'RPV' : 'Precat.' }}</td>
                            <td>{{ \App\Domains\Writs\Services\WritReceiptTimeReport::courtKey($r['writ']->court) }}</td>
                            <td class="text-right">{{ $r['writ']->paid_at?->format('d/m/Y') }}</td>
                            <td class="text-right">{{ $r['writ']->finalized_at?->format('d/m/Y') }}</td>
                            <td class="text-right">{{ number_format($r['months'], 1, ',', '.') }} meses ({{ $r['days'] }} dias)</td>
                            <td class="text-right">{{ $r['estimated_months'] ? $r['estimated_months'].' meses' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

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

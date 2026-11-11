<?php

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use App\Domains\Writs\Services\WritReceiptTimeReport;
use Livewire\Attributes\Layout;
use App\Support\Concerns\SortsColumns;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use SortsColumns;

    protected function sortListKey(): string
    {
        return 'writs_report_open';
    }

    protected function sortLists(): array
    {
        return ['writs_report_open', 'writs_report_groups', 'writs_report_times', 'writs_report_profit'];
    }

    public function mount(): void
    {
        $this->loadSort();
    }

    public function with(WritProfitabilityCalculator $calc, WritReceiptTimeReport $times): array
    {
        $timeRows = $times->rows();
        $timeSummary = $times->summary();
        $situationByType = Writ::with('assignors.contact')->whereIn('stage', ['paid', 'petitioning', 'awaiting_receipt', 'finalized'])
            ->orderBy('paid_at')->get()
            ->groupBy(fn (Writ $w) => $w->type === 'rpv' ? 'RPV' : 'Precatório')->sortKeys(SORT_STRING | SORT_FLAG_CASE)->reverse();
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

        $situationByType = $situationByType->map(fn ($list) => $this->sortRows($list, 'writs_report_open', [
            'beneficiary' => fn (Writ $w) => $w->beneficiaryName(),
            'process' => fn (Writ $w) => $w->process_number ?: '#'.$w->id,
            'type' => fn (Writ $w) => $w->type === 'rpv' ? 'RPV' : 'Precatório',
            'purchase' => fn (Writ $w) => $w->paid_at,
            'forecast' => fn (Writ $w) => $w->estimatedReceiptDeadline(),
            'status' => fn (Writ $w) => $w->deadlineStatus()['label'] ?? 'Sem previsão',
        ], [[fn (Writ $w) => $w->id, 'asc']]));
        $groupAcc = [
            'group' => fn ($s) => $s['label'],
            'quantity' => fn ($s) => (float) $s['count'],
            'real_avg' => fn ($s) => (float) $s['avg_months'],
            'range' => fn ($s) => (float) $s['min_months'],
            'estimated_avg' => fn ($s) => $s['avg_estimated'] === null ? null : (float) $s['avg_estimated'],
            'diff' => fn ($s) => $s['avg_estimated'] === null ? null : (float) $s['avg_months'] - (float) $s['avg_estimated'],
        ];
        foreach (['by_type', 'by_court'] as $groupKey) {
            if (isset($timeSummary[$groupKey])) {
                $timeSummary[$groupKey] = $this->sortRows($timeSummary[$groupKey], 'writs_report_groups', $groupAcc)->all();
            }
        }
        $timeRows = $this->sortRows($timeRows, 'writs_report_times', [
            'process' => fn ($r) => $r['writ']->process_number ?: '#'.$r['writ']->id,
            'type' => fn ($r) => $r['writ']->type === 'rpv' ? 'RPV' : 'Precatório',
            'court' => fn ($r) => \App\Domains\Writs\Services\WritReceiptTimeReport::courtKey($r['writ']->court),
            'purchase' => fn ($r) => $r['writ']->paid_at,
            'receipt' => fn ($r) => $r['writ']->finalized_at,
            'real' => fn ($r) => (float) $r['months'],
            'estimated' => fn ($r) => $r['estimated_months'] ? (float) $r['estimated_months'] : null,
        ], [[fn ($r) => $r['writ']->id, 'asc']])->all();
        $rows = $this->sortRows($rows, 'writs_report_profit', [
            'process' => fn ($r) => $r['writ']->process_number ?: '#'.$r['writ']->id,
            'type' => fn ($r) => $r['writ']->type === 'rpv' ? 'RPV' : 'Precatório',
            'paid' => fn ($r) => (float) $r['writ']->totalCost(),
            'received' => fn ($r) => (float) $r['writ']->actual_receipt_amount,
            'profit' => fn ($r) => (float) $r['profitability']['profit_amount'],
            'percent' => fn ($r) => (float) $r['profitability']['profit_percentage'],
            'days' => fn ($r) => $r['profitability']['days_elapsed'],
            'monthly' => fn ($r) => $r['profitability']['monthly_rate'],
        ], [[fn ($r) => $r['writ']->id, 'asc']]);

        return compact('rows', 'totalInvested', 'totalReceived', 'totalProfit', 'avgPct', 'avgMonthly', 'timeRows', 'timeSummary', 'situationByType');
    }
}; ?>

<x-slot name="header">Requisitórios · Relatórios</x-slot>

<div class="flex flex-col gap-md">
    <x-back-button :href="route('writs.kanban')" />
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

    <x-fx.card data-situation-report>
        <h3 class="text-md font-semibold mb-sm">Situação dos requisitórios comprados</h3>
        <x-fx.sort-mobile list="writs_report_open" :sort-by="$sortBy" :sort-dir="$sortDir" primary />
        @forelse ($situationByType as $typeLabel => $list)
            <h4 class="mt-sm mb-xs text-sm font-semibold">{{ $typeLabel }} ({{ $list->count() }})</h4>
            <div class="overflow-x-auto">
            <table class="fx-table w-full text-sm mb-sm">
                <thead><tr><x-fx.sort-th column="beneficiary" label="Beneficiário" list="writs_report_open" :sorts="$sorts" /><x-fx.sort-th column="process" label="Processo" list="writs_report_open" :sorts="$sorts" /><x-fx.sort-th column="type" label="Tipo" list="writs_report_open" :sorts="$sorts" /><x-fx.sort-th column="purchase" label="Compra" list="writs_report_open" :sorts="$sorts" align="right" /><x-fx.sort-th column="forecast" label="Previsão" list="writs_report_open" :sorts="$sorts" align="right" /><x-fx.sort-th column="status" label="Situação" list="writs_report_open" :sorts="$sorts" /></tr></thead>
                <tbody>
                    @foreach ($list as $w)
                        @php $st = $w->deadlineStatus(); $col = ['on_time' => '#16a34a', 'due_soon' => '#ca8a04', 'overdue' => '#dc2626', 'received' => '#6b7280'][$st['key'] ?? ''] ?? '#6b7280'; @endphp
                        <tr>
                            <td class="font-semibold">{{ $w->beneficiaryName() }}</td>
                            <td><a href="{{ route('writs.show', $w) }}" class="hover:text-primary-500 whitespace-nowrap">{{ $w->process_number ?: '#'.$w->id }}</a></td>
                            <td>{{ $w->type === 'rpv' ? 'RPV' : 'Precatório' }}</td>
                            <td class="text-right">{{ $w->paid_at?->format('d/m/Y') ?? 'sem data' }}</td>
                            <td class="text-right">{{ $w->estimatedReceiptDeadline()?->format('d/m/Y') ?? '—' }}</td>
                            <td style="color: {{ $col }};" class="font-semibold whitespace-nowrap">{{ $st['label'] ?? 'Sem previsão' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @empty
            <div class="text-sm text-mono-600">Nenhum requisitório comprado.</div>
        @endforelse
    </x-fx.card>

    <x-fx.card data-receipt-times>
        <h3 class="text-md font-semibold mb-sm">Tempo de recebimento (compra → recebimento)</h3>
        <p class="mb-sm text-xs text-mono-600">Data da compra (pagamento da cessão) até a data real de recebimento, só dos requisitórios finalizados. Comparado com o prazo estimado cadastrado.</p>
        @if (empty($timeRows))
            <div class="text-sm text-mono-600">Nenhum requisitório finalizado com data de compra e de recebimento.</div>
        @else
            @foreach (['by_type' => 'Por tipo', 'by_court' => 'Por tribunal e tipo'] as $key => $title)
                <h4 class="mt-sm mb-xs text-sm font-semibold">{{ $title }}</h4>
                <table class="fx-table w-full text-sm mb-sm">
                    <thead><tr><x-fx.sort-th column="group" label="Grupo" list="writs_report_groups" :sorts="$sorts" /><x-fx.sort-th column="quantity" label="Qtde" list="writs_report_groups" :sorts="$sorts" align="right" /><x-fx.sort-th column="real_avg" label="Média real" list="writs_report_groups" :sorts="$sorts" align="right" /><x-fx.sort-th column="range" label="Mín–máx" list="writs_report_groups" :sorts="$sorts" align="right" /><x-fx.sort-th column="estimated_avg" label="Média estimada" list="writs_report_groups" :sorts="$sorts" align="right" /><x-fx.sort-th column="diff" label="Diferença" list="writs_report_groups" :sorts="$sorts" align="right" /></tr></thead>
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
                <thead><tr><x-fx.sort-th column="process" label="Processo" list="writs_report_times" :sorts="$sorts" /><x-fx.sort-th column="type" label="Tipo" list="writs_report_times" :sorts="$sorts" /><x-fx.sort-th column="court" label="Tribunal" list="writs_report_times" :sorts="$sorts" /><x-fx.sort-th column="purchase" label="Compra" list="writs_report_times" :sorts="$sorts" align="right" /><x-fx.sort-th column="receipt" label="Recebimento" list="writs_report_times" :sorts="$sorts" align="right" /><x-fx.sort-th column="real" label="Real" list="writs_report_times" :sorts="$sorts" align="right" /><x-fx.sort-th column="estimated" label="Estimado" list="writs_report_times" :sorts="$sorts" align="right" /></tr></thead>
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
                <thead><tr><x-fx.sort-th column="process" label="Processo" list="writs_report_profit" :sorts="$sorts" /><x-fx.sort-th column="type" label="Tipo" list="writs_report_profit" :sorts="$sorts" /><x-fx.sort-th column="paid" label="Pago" list="writs_report_profit" :sorts="$sorts" align="right" /><x-fx.sort-th column="received" label="Recebido" list="writs_report_profit" :sorts="$sorts" align="right" /><x-fx.sort-th column="profit" label="Lucro" list="writs_report_profit" :sorts="$sorts" align="right" /><x-fx.sort-th column="percent" label="%" list="writs_report_profit" :sorts="$sorts" align="right" /><x-fx.sort-th column="days" label="Dias" list="writs_report_profit" :sorts="$sorts" align="right" /><x-fx.sort-th column="monthly" label="% a.m." list="writs_report_profit" :sorts="$sorts" align="right" /></tr></thead>
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

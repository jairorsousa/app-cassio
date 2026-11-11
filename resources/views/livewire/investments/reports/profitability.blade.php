<?php

use App\Domains\Investments\Services\InvestmentAnalyticsService;
use Livewire\Attributes\Layout;
use App\Support\Concerns\SortsColumns;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use SortsColumns;

    protected function sortListKey(): string
    {
        return 'profitability';
    }

    protected function sortLists(): array
    {
        return ['profitability'];
    }

    public string $from = '';
    public string $to = '';

    public function mount(): void {
        $this->loadSort(); $this->from = \App\Support\BusinessDate::now()->startOfYear()->format('Y-m-d'); $this->to = \App\Support\BusinessDate::today(); }

    public function rules(): array { return ['from' => 'required|date', 'to' => 'required|date|after_or_equal:from|before_or_equal:'.\App\Support\BusinessDate::today()]; }

    public function applyFilters(): void { $this->validate(); }

    public function export()
    {
        $this->validate();
        $report = app(InvestmentAnalyticsService::class)->period($this->from, $this->to);
        return response()->streamDownload(function () use ($report) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Ativo', 'Compras (R$)', 'Vendas (R$)', 'Taxas (R$)', 'Resultado realizado (R$)', 'Proventos (R$)', 'Resultado total (R$)'], ';', '"', '');
            foreach ($report['rows'] as $row) {
                $ticker = preg_match('/^[=+@\\-]/', $row['ticker']) ? "'".$row['ticker'] : $row['ticker'];
                fputcsv($stream, [$ticker, ...array_map(fn ($key) => number_format($row[$key], 2, ',', ''), ['buys', 'sales', 'fees', 'realized', 'income', 'result'])], ';', '"', '');
            }
            fclose($stream);
        }, 'investimentos-'.$this->from.'-'.$this->to.'.csv');
    }

    public function with(): array
    {
        $valid = validator(['from' => $this->from, 'to' => $this->to], $this->rules())->passes();
        $report = $valid ? app(InvestmentAnalyticsService::class)->period($this->from, $this->to) : null;
        if ($report) {
            $report['rows'] = $this->sortRows($report['rows'], 'profitability', [
                'asset' => fn ($r) => $r['ticker'],
                'purchases' => fn ($r) => (float) $r['buys'],
                'sales' => fn ($r) => (float) $r['sales'],
                'gain' => fn ($r) => (float) $r['realized'],
                'income' => fn ($r) => (float) $r['income'],
                'result' => fn ($r) => (float) $r['result'],
            ], [[fn ($r) => $r['ticker'], 'asc']]);
        }

        return ['report' => $report];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>
<div class="investment-area">
    <x-investments.subnav />
    <x-investments.b3-notice what="report" />
    <div><h2 class="text-2xl font-bold">Rentabilidade e resultados</h2><p class="mt-2 text-sm text-mono-600">Analise o resultado das vendas e os proventos efetivamente recebidos no período.</p></div>
    <x-jr.card>
        <form wire:submit="applyFilters" class="flex flex-wrap items-end gap-4">
            <x-jr.input label="De" name="from" type="date" wire:model="from" />
            <x-jr.input label="Até" name="to" type="date" wire:model="to" />
            <x-jr.button type="submit">Aplicar período</x-jr.button>
            <x-jr.button variant="standard" wire:click="export"><span class="material-icons-outlined text-[18px]">download</span>Exportar CSV</x-jr.button>
        </form>
    </x-jr.card>
    @if ($report)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-investments.metric label="Resultado realizado" :value="'R$ '.number_format($report['realized'], 2, ',', '.')" hint="Vendas menos custo de aquisição e taxas" :positive="$report['realized'] >= 0" icon="trending_up" />
            <x-investments.metric label="Proventos recebidos" :value="'R$ '.number_format($report['income'], 2, ',', '.')" hint="Recebimentos dentro do período" :positive="true" />
            <x-investments.metric label="Resultado do período" :value="'R$ '.number_format($report['result'], 2, ',', '.')" hint="Resultado das vendas + proventos" :positive="$report['result'] >= 0" icon="query_stats" />
            <x-investments.metric label="Taxas registradas" :value="'R$ '.number_format($report['fees'], 2, ',', '.')" hint="Já consideradas no custo de compras e vendas" icon="receipt_long" />
        </div>
        <x-jr.card>
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">Resultado por ativo</h3><span class="text-xs text-mono-600">{{ count($report['rows']) }} ativos com movimentação</span></div>
            @if ($report['rows']->isEmpty())
                <x-fx.empty-state icon="📊" title="Sem resultados neste período" description="Registre suas operações ou selecione outro período." />
            @else
                <x-fx.sort-mobile list="profitability" :sort-by="$sortBy" :sort-dir="$sortDir" primary /><div class="overflow-x-auto"><table class="investment-table">
                    <thead><tr><x-fx.sort-th column="asset" label="Ativo" list="profitability" :sorts="$sorts" /><x-fx.sort-th column="purchases" label="Compras" list="profitability" :sorts="$sorts" align="right" /><x-fx.sort-th column="sales" label="Vendas" list="profitability" :sorts="$sorts" align="right" /><x-fx.sort-th column="gain" label="Lucro / prejuízo nas vendas" list="profitability" :sorts="$sorts" align="right" /><x-fx.sort-th column="income" label="Proventos" list="profitability" :sorts="$sorts" align="right" /><x-fx.sort-th column="result" label="Resultado" list="profitability" :sorts="$sorts" align="right" /></tr></thead>
                    <tbody>@foreach ($report['rows'] as $row)<tr><td class="font-semibold">{{ $row['ticker'] }}</td>@foreach (['buys', 'sales', 'realized', 'income', 'result'] as $key)<td class="text-right whitespace-nowrap {{ $key === 'result' ? ($row[$key] >= 0 ? 'text-up font-bold' : 'text-down font-bold') : '' }}">R$ {{ number_format($row[$key], 2, ',', '.') }}</td>@endforeach</tr>@endforeach</tbody>
                </table></div>
            @endif
        </x-jr.card>
        <x-jr.card><h3 class="mb-3 font-bold">Como interpretar os resultados</h3><p class="text-sm leading-relaxed text-mono-600">Compras e aplicações são movimentações de capital. O resultado realizado considera apenas as vendas, descontando o custo médio e as taxas registradas. A valorização dos ativos ainda em carteira aparece na aba Carteira. Os valores deste relatório não são uma taxa de rentabilidade anualizada e não incluem impostos não lançados.</p></x-jr.card>
    @endif
</div>

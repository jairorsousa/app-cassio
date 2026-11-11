<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Dashboard\Support\ReportPeriod;
use App\Support\ColumnSorter;
use App\Support\Concerns\SortsColumns;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use SortsColumns;

    #[Url(history: true, keep: true)]
    public string $month = '';

    #[Url(as: 'de')]
    public string $start = '';

    #[Url(as: 'ate')]
    public string $end = '';

    #[Url(as: 'conta')]
    public string $account = '';

    #[Url(as: 'categoria')]
    public string $category = '';

    #[Url(as: 'pessoa')]
    public string $person = '';

    #[Url(as: 'projecao')]
    public int $horizon = 3;

    public string $chartType = 'bar';

    public function mount(): void
    {
        $this->normalizeMonth($this->month);
        if (! in_array($this->horizon, [3, 6, 12], true)) {
            $this->horizon = 3;
        }
        $this->loadSort();
    }

    protected function sortListKey(): string
    {
        return 'cashflow_items';
    }

    protected function sortLists(): array
    {
        return ['cashflow_items', 'cashflow_months', 'cashflow_categories', 'cashflow_accounts'];
    }

    public function updatedMonth(mixed $value): void
    {
        $this->normalizeMonth(is_string($value) ? $value : '');
        $this->start = $this->end = '';
    }

    public function useMonthShortcut(): void
    {
        $this->start = $this->end = '';
    }

    private function normalizeMonth(?string $value): void
    {
        $value = trim((string) $value);
        $this->month = preg_match('/^\d{4}-\d{2}$/', $value) ? $value : now(\App\Support\BusinessDate::timezone())->format('Y-m');
    }

    public function period(): ReportPeriod
    {
        if ($this->start !== '' && $this->end !== '') {
            return ReportPeriod::fromInput('custom', $this->start, $this->end);
        }

        return ReportPeriod::month($this->month);
    }

    public function filters(): array
    {
        return ['account_id' => $this->account !== '' ? (int) $this->account : null, 'category_id' => $this->category !== '' ? (int) $this->category : null, 'person' => $this->person !== '' ? $this->person : null];
    }

    public function exportQuery(): array
    {
        $p = $this->period();

        return array_filter(['de' => $p->start->toDateString(), 'ate' => $p->end->toDateString(), 'conta' => $this->account, 'categoria' => $this->category, 'pessoa' => $this->person, 'projecao' => $this->horizon], fn ($v) => $v !== '' && $v !== null);
    }

    public function with(CashflowReportService $service): array
    {
        $this->normalizeMonth($this->month);
        if (! in_array($this->chartType, ['bar', 'line', 'area'], true)) {
            $this->chartType = 'bar';
        }
        $period = $this->period();
        $report = $service->build($period, $this->filters());
        $projection = $service->projection($this->horizon, $this->filters());

        $real = $report['realized'];
        $totalIncome = (float) $real['by_category']->sum('income');
        $totalExpense = (float) $real['by_category']->sum('expense');

        return [
            // Chaves mantidas por compatibilidade com a tela e os testes anteriores.
            'byCategory' => $real['by_category'],
            'byAccount' => $real['by_account'],
            'totalIncome' => $totalIncome,
            'totalExpense' => $totalExpense,
            'ownIncome' => $real['own_income'],
            'ownExpense' => $real['own_expense'],
            'result' => $real['result'],
            'thirdPartyIncome' => $real['third_party_income'],
            'recoveredExpenses' => $real['recovered_expenses'],
            'pendingRepasses' => $real['pending_repasses'],
            'cashResult' => $real['cash_variation'],
            'neutralIn' => max(0, $totalIncome - $real['own_income']),
            'neutralOut' => max(0, $totalExpense - $real['own_expense']),
            'accountsBalance' => $report['cash']['accounts_balance'],
            'thirdPartyBalance' => $report['cash']['third_party_balance'],
            'ownBalance' => $report['cash']['own_balance'],
            'period' => $period,
            'r' => $report,
            'projection' => $projection,
            'accounts' => BankAccount::orderBy('name')->get(['id', 'name', 'type']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'people' => $service->personOptions(),
            'exportQuery' => $this->exportQuery(),
            'forecastItems' => (function () use ($report) {
                [$by, $dir] = $this->sortState('cashflow_items');
                $items = collect($report['forecast']['items'] ?? []);

                return ColumnSorter::sort($items, $by, $dir, [
                    'date' => fn ($i) => $i['date'] ?? null,
                    'source' => fn ($i) => $i['source'] ?? '',
                    'description' => fn ($i) => $i['description'] ?? '',
                    'inflow' => fn ($i) => (($i['direction'] ?? '') === 'in') ? (float) $i['amount'] : 0,
                    'outflow' => fn ($i) => (($i['direction'] ?? '') === 'out') ? (float) $i['amount'] : 0,
                ], $by === 'date' ? [[fn ($i) => $i['description'] ?? '', $dir]] : [[fn ($i) => $i['date'] ?? '', 'desc']]);
            })(),
            'projectionRows' => (function () use ($projection) {
                [$by, $dir] = $this->sortState('cashflow_months');

                return ColumnSorter::sort(collect($projection['rows'] ?? []), $by, $dir, [
                    'month' => fn ($r) => $r['key'] ?? $r['month'] ?? $r['label'] ?? '',
                    'income' => fn ($r) => (float) ($r['income'] ?? 0),
                    'expense' => fn ($r) => (float) ($r['expense'] ?? 0),
                    'net' => fn ($r) => (float) ($r['net'] ?? 0),
                    'balance' => fn ($r) => (float) ($r['balance'] ?? 0),
                ], [[fn ($r) => $r['label'] ?? '', 'asc']]);
            })(),
            'categoryRows' => (function () use ($real) {
                [$by, $dir] = $this->sortState('cashflow_categories');
                $rows = collect($real['by_category'])->map(fn ($row, $name) => array_merge((array) $row, ['name' => $name]))->values();

                return ColumnSorter::sort($rows, $by, $dir, [
                    'category' => fn ($r) => $r['name'],
                    'own_income' => fn ($r) => (float) ($r['own_income'] ?? 0),
                    'own_expense' => fn ($r) => (float) ($r['own_expense'] ?? 0),
                    'other_in' => fn ($r) => (float) ($r['third_party_in'] ?? 0),
                    'other_out' => fn ($r) => (float) ($r['third_party_out'] ?? 0),
                    'result' => fn ($r) => (float) (($r['own_income'] ?? 0) - ($r['own_expense'] ?? 0)),
                ], [[fn ($r) => $r['name'], 'asc']]);
            })(),
            'accountRows' => (function () use ($real) {
                [$by, $dir] = $this->sortState('cashflow_accounts');
                $rows = collect($real['by_account'])->map(fn ($row, $name) => array_merge((array) $row, ['name' => $name]))->values();

                return ColumnSorter::sort($rows, $by, $dir, [
                    'account' => fn ($r) => $r['name'],
                    'inflow' => fn ($r) => (float) ($r['in'] ?? 0),
                    'outflow' => fn ($r) => (float) ($r['out'] ?? 0),
                    'balance' => fn ($r) => (float) (($r['in'] ?? 0) - ($r['out'] ?? 0)),
                ], [[fn ($r) => $r['name'], 'asc']]);
            })(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

@php
    $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $pct = fn ($c) => $c['pct'] === null ? 'sem base de comparação' : ($c['pct'] >= 0 ? '+' : '').number_format($c['pct'], 1, ',', '.').'%';
    $input = 'h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-3 text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none';
    $lbl = 'block text-fs-12 font-medium text-cryptex-text-tertiary';
    $real = $r['realized'];
@endphp

<div class="flex flex-col gap-md">
    <x-banking.subnav />
    <x-fx.card>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-sm items-end">
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-month">Mês (atalho)</label>
                <input id="cashflow-month" type="month" wire:model.live="month" wire:key="cashflow-month-input" class="{{ $input }}" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-start">De</label>
                <input id="cashflow-start" type="date" wire:model.live.debounce.600ms="start" class="{{ $input }}" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-end">Até</label>
                <input id="cashflow-end" type="date" wire:model.live.debounce.600ms="end" class="{{ $input }}" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-account">Conta</label>
                <select id="cashflow-account" wire:model.live="account" class="{{ $input }}">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}{{ $a->type === 'repasse' ? ' (repasse)' : '' }}</option>@endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-category">Categoria</label>
                <select id="cashflow-category" wire:model.live="category" class="{{ $input }}">
                    <option value="">Todas</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $lbl }}" for="cashflow-person">Pessoa</label>
                <select id="cashflow-person" wire:model.live="person" class="{{ $input }}">
                    <option value="">Todas</option>
                    @foreach ($people as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                </select>
            </div>
            <livewire:banking.regime-switch :key="'regime-'.$month" />
        </div>
        <div class="mt-sm flex flex-wrap items-center gap-sm text-fs-12 text-cryptex-text-secondary">
            <span>Período: <strong>{{ $period->label() }}</strong> · Saldos em {{ \Illuminate\Support\Carbon::parse($r['position_date'])->format('d/m/Y') }}</span>
            @if ($start !== '' || $end !== '')
                <button type="button" wire:click="useMonthShortcut" class="text-cryptex-brand-400 font-semibold">Voltar ao mês</button>
            @endif
            <span class="ml-auto flex gap-sm">
                <a href="{{ route('banking.reports.cashflow.export', ['format' => 'csv'] + $exportQuery) }}" class="min-h-[40px] inline-flex items-center px-3 rounded-sm border border-cryptex-border-default font-semibold text-cryptex-text-primary">Exportar CSV</a>
                <a href="{{ route('banking.reports.cashflow.export', ['format' => 'pdf'] + $exportQuery) }}" class="min-h-[40px] inline-flex items-center px-3 rounded-sm border border-cryptex-border-default font-semibold text-cryptex-text-primary">Exportar PDF</a>
            </span>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        @foreach ([['Receitas próprias', $real['own_income'], 'income', 'text-system-up'], ['Despesas próprias', $real['own_expense'], 'expense', 'text-system-down'], ['Resultado próprio', $real['result'], 'result', $real['result'] >= 0 ? 'text-system-up' : 'text-system-down']] as [$t, $v, $k, $cls])
            <x-fx.card>
                <div class="text-xxs text-mono-600 uppercase">{{ $t }}</div>
                <div class="text-xl font-bold {{ $cls }}">{{ $money($v) }}</div>
                <div class="text-fs-12 text-cryptex-text-tertiary mt-1">Período anterior {{ $money($r['comparison'][$k]['previous']) }} · {{ $r['comparison'][$k]['diff'] >= 0 ? '+' : '' }}{{ $money($r['comparison'][$k]['diff']) }} ({{ $pct($r['comparison'][$k]) }})</div>
            </x-fx.card>
        @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Saldo nas contas</div>
            <div class="text-lg font-bold">{{ $money($r['cash']['accounts_balance']) }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Dinheiro de terceiros</div>
            <div class="text-lg font-bold">{{ $money($r['cash']['third_party_balance']) }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Saldo próprio</div>
            <div class="text-lg font-bold {{ $r['cash']['own_balance'] >= 0 ? '' : 'text-system-down' }}">{{ $money($r['cash']['own_balance']) }}</div>
            <div class="text-fs-12 text-cryptex-text-tertiary">Mesmo cálculo de /banking, em {{ \Illuminate\Support\Carbon::parse($r['position_date'])->format('d/m/Y') }}.</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        <div class="flex flex-wrap items-center gap-sm mb-sm">
            <h3 class="text-md font-semibold">Resultado próprio por mês</h3>
            <div class="ml-auto inline-flex rounded-sm border border-cryptex-border-default overflow-hidden text-fs-12" role="group" aria-label="Tipo de gráfico">
                @foreach (['bar' => 'Barras', 'line' => 'Linha', 'area' => 'Área'] as $t => $l)
                    <button type="button" wire:click="$set('chartType', '{{ $t }}')" aria-pressed="{{ $chartType === $t ? 'true' : 'false' }}" class="px-2 py-1 min-h-[32px] {{ $chartType === $t ? 'bg-cryptex-brand-400 text-white' : 'text-cryptex-text-secondary' }}">{{ $l }}</button>
                @endforeach
            </div>
        </div>
        <x-dashboard.chart :type="$chartType" title="Receitas, despesas e resultado próprios por mês" :labels="array_column($r['months'], 'label')"
            :datasets="[['name' => 'Receitas', 'values' => array_column($r['months'], 'income'), 'color' => '#22c55e'], ['name' => 'Despesas', 'values' => array_column($r['months'], 'expense'), 'color' => '#ef4444'], ['name' => 'Resultado', 'values' => array_column($r['months'], 'result'), 'color' => '#60a5fa']]" />
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Realizado x previsto no período</h3>
        <div class="grid gap-4 text-sm grid-cols-2 md:grid-cols-5">
            <div>Entradas realizadas<strong class="mt-1 block text-lg">{{ $money($real['gross_in']) }}</strong></div>
            <div>Saídas realizadas<strong class="mt-1 block text-lg">{{ $money($real['gross_out']) }}</strong></div>
            <div>Variação do caixa<strong class="mt-1 block text-lg">{{ $money($real['cash_variation']) }}</strong></div>
            <div>Entradas previstas<strong class="mt-1 block text-lg text-system-up">{{ $money($r['forecast']['income']) }}</strong></div>
            <div>Saídas previstas<strong class="mt-1 block text-lg text-system-down">{{ $money($r['forecast']['expense']) }}</strong></div>
        </div>
        <p class="mt-3 text-xs text-mono-600">Realizado = lançamentos liquidados (movimento bancário, sem contas de repasse). Previsto = lançamentos pendentes, saldo das faturas que vencem no período, recorrências ativas ainda não geradas, parcelas em aberto de financiamento de bens e requisitórios em Pago, Peticionamento ou Aguardando recebimento com previsão (Monitorar nunca entra).</p>
        @if ($forecastItems->isNotEmpty())
            <x-fx.sort-mobile list="cashflow_items" :sorts="$sorts" />
            <div class="overflow-x-auto mt-sm">
                <table class="fx-table w-full text-sm">
                    <thead><tr>
                        <x-fx.sort-th column="date" label="Data" :sorts="$sorts" list="cashflow_items" />
                        <x-fx.sort-th column="source" label="Origem" :sorts="$sorts" list="cashflow_items" />
                        <x-fx.sort-th column="description" label="Descrição" :sorts="$sorts" list="cashflow_items" />
                        <x-fx.sort-th column="inflow" label="Entrada" align="right" :sorts="$sorts" list="cashflow_items" />
                        <x-fx.sort-th column="outflow" label="Saída" align="right" :sorts="$sorts" list="cashflow_items" />
                    </tr></thead>
                    <tbody>
                        @foreach ($forecastItems->take(60) as $item)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($item['date'])->format('d/m/Y') }}</td>
                                <td>{{ $item['source'] }}</td>
                                <td>{{ $item['description'] }}@isset($item['profit'])<span class="text-xs text-mono-600"> · lucro {{ $money($item['profit']) }} / capital {{ $money($item['capital']) }}</span>@endisset</td>
                                <td class="text-right text-system-up">{{ $item['direction'] === 'in' ? $money($item['amount']) : '' }}</td>
                                <td class="text-right text-system-down">{{ $item['direction'] === 'out' ? $money($item['amount']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fx.card>

    <x-fx.card>
        <div class="flex flex-wrap items-center gap-sm mb-sm">
            <h3 class="text-md font-semibold">Projeção de caixa</h3>
            <div class="ml-auto inline-flex rounded-sm border border-cryptex-border-default overflow-hidden text-fs-12" role="group" aria-label="Horizonte da projeção">
                @foreach ([3, 6, 12] as $h)
                    <button type="button" wire:click="$set('horizon', {{ $h }})" aria-pressed="{{ $horizon === $h ? 'true' : 'false' }}" class="px-3 py-1 min-h-[32px] {{ $horizon === $h ? 'bg-cryptex-brand-400 text-white' : 'text-cryptex-text-secondary' }}">{{ $h }} meses</button>
                @endforeach
            </div>
        </div>
        <p class="text-xs text-mono-600 mb-sm">Parte do {{ $account !== '' ? 'saldo da conta' : 'saldo próprio' }} de hoje ({{ $money($projection['start_balance']) }}) e soma o previsto de cada mês. Estimativa; não grava nada.
            @if ($projection['overdue_writs']['count'])
                <strong class="text-system-down">{{ $projection['overdue_writs']['count'] }} requisitório(s) com previsão já vencida ({{ $money($projection['overdue_writs']['amount']) }}) ficaram fora da projeção até receberem nova previsão.</strong>
            @endif
        </p>
        <x-dashboard.chart type="line" title="Saldo projetado ao fim de cada mês" :labels="array_column($projection['rows'], 'label')" :datasets="[['name' => 'Saldo projetado', 'values' => array_column($projection['rows'], 'balance'), 'color' => '#60a5fa']]" />
        <x-fx.sort-mobile list="cashflow_months" :sorts="$sorts" />
        <div class="overflow-x-auto mt-sm">
            <table class="fx-table w-full text-sm">
                <thead><tr>
                    <x-fx.sort-th column="month" label="Mês" :sorts="$sorts" list="cashflow_months" />
                    <x-fx.sort-th column="income" label="Entradas previstas" align="right" :sorts="$sorts" list="cashflow_months" />
                    <x-fx.sort-th column="expense" label="Saídas previstas" align="right" :sorts="$sorts" list="cashflow_months" />
                    <x-fx.sort-th column="net" label="Líquido" align="right" :sorts="$sorts" list="cashflow_months" />
                    <x-fx.sort-th column="balance" label="Saldo projetado" align="right" :sorts="$sorts" list="cashflow_months" />
                </tr></thead>
                <tbody>
                    @foreach ($projectionRows as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="text-right text-system-up">{{ $money($row['income']) }}</td><td class="text-right text-system-down">{{ $money($row['expense']) }}</td><td class="text-right">{{ $money($row['net']) }}</td><td class="text-right font-semibold">{{ $money($row['balance']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Recebimentos de processo do período</h3>
        <div class="grid gap-4 md:grid-cols-4 text-sm">
            <div>Receitas após separar terceiros<strong class="mt-1 block text-lg text-system-up">{{ $money($real['own_income']) }}</strong></div>
            <div>Valores de clientes, corretores e escritórios<strong class="mt-1 block text-lg">{{ $money($real['third_party_income']) }}</strong></div>
            <div>Despesas recuperadas<strong class="mt-1 block text-lg">{{ $money($real['recovered_expenses']) }}</strong></div>
            <div>Requisitórios: Juros Ganho / Retorno de capital<strong class="mt-1 block text-lg">{{ $money($real['writ_profit']) }} / {{ $money($real['writ_capital']) }}</strong></div>
        </div>
        <p class="mt-3 text-xs text-mono-600">Transferências, repasses, adiantamentos e principal emprestado ou devolvido não entram no resultado próprio; juros recebidos entram. Compra no cartão entra pela data da compra (competência) ou pelo pagamento da fatura (caixa), uma única vez.</p>
    </x-fx.card>

    <x-fx.card class="overflow-x-auto">
        <h3 class="text-md font-semibold mb-sm">Resultado próprio e valores de terceiros por categoria</h3>
        <x-fx.sort-mobile list="cashflow_categories" :sorts="$sorts" />
        <table class="fx-table w-full text-sm">
            <thead><tr>
                <x-fx.sort-th column="category" label="Categoria" :sorts="$sorts" list="cashflow_categories" />
                <x-fx.sort-th column="own_income" label="Receita própria" align="right" :sorts="$sorts" list="cashflow_categories" />
                <x-fx.sort-th column="own_expense" label="Despesa própria" align="right" :sorts="$sorts" list="cashflow_categories" />
                <x-fx.sort-th column="other_in" label="Outras entradas" align="right" :sorts="$sorts" list="cashflow_categories" />
                <x-fx.sort-th column="other_out" label="Outras saídas" align="right" :sorts="$sorts" list="cashflow_categories" />
                <x-fx.sort-th column="result" label="Resultado próprio" align="right" :sorts="$sorts" list="cashflow_categories" />
            </tr></thead>
            <tbody>
                @foreach ($categoryRows as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td class="text-right text-system-up">{{ $money($row['own_income']) }}</td>
                        <td class="text-right text-system-down">{{ $money($row['own_expense']) }}</td>
                        <td class="text-right">{{ $money($row['third_party_in']) }}</td>
                        <td class="text-right">{{ $money($row['third_party_out']) }}</td>
                        <td class="text-right font-semibold">{{ $money($row['own_income'] - $row['own_expense']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-fx.card>

    <x-fx.card class="overflow-x-auto">
        <h3 class="text-md font-semibold mb-sm">Por conta</h3>
        <x-fx.sort-mobile list="cashflow_accounts" :sorts="$sorts" />
        <table class="fx-table w-full text-sm">
            <thead><tr>
                <x-fx.sort-th column="account" label="Conta" :sorts="$sorts" list="cashflow_accounts" />
                <x-fx.sort-th column="inflow" label="Entradas" align="right" :sorts="$sorts" list="cashflow_accounts" />
                <x-fx.sort-th column="outflow" label="Saídas" align="right" :sorts="$sorts" list="cashflow_accounts" />
                <x-fx.sort-th column="balance" label="Saldo do período" align="right" :sorts="$sorts" list="cashflow_accounts" />
            </tr></thead>
            <tbody>
                @foreach ($accountRows as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="text-right text-system-up">{{ $money($row['in']) }}</td><td class="text-right text-system-down">{{ $money($row['out']) }}</td><td class="text-right font-semibold">{{ $money($row['in'] - $row['out']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-fx.card>
</div>

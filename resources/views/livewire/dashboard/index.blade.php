<?php

use App\Domains\Dashboard\Services\DashboardSnapshotService;
use App\Domains\Dashboard\Services\DashboardWidgetService;
use App\Domains\Dashboard\Support\DashboardPreferences;
use App\Domains\Dashboard\Support\ReportPeriod;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public array $prefs = [];

    public bool $customizing = false;

    public string $preset = 'this_month';

    public ?string $start = null;

    public ?string $end = null;

    public function mount(): void
    {
        $this->prefs = DashboardPreferences::for(auth()->user());
        $this->preset = $this->prefs['period']['preset'];
        $this->start = $this->prefs['period']['start'];
        $this->end = $this->prefs['period']['end'];
        // Volta do aviso de sessão expirada já com o modo Personalizar aberto.
        $this->customizing = request()->boolean('personalizar');
        if ($this->preset === 'custom' && (! $this->start || ! $this->end)) {
            $this->preset = 'this_month';
        }
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col gap-space-5 animate-pulse">
            <div class="h-[96px] bg-cryptex-bg-tertiary rounded-md"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-space-5">
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
                <div class="h-[112px] bg-cryptex-bg-tertiary rounded-md"></div>
            </div>
            <div class="h-[192px] bg-cryptex-bg-tertiary rounded-md"></div>
        </div>
        HTML;
    }

    private function current(): array
    {
        return $this->prefs = DashboardPreferences::normalize($this->prefs ?: DashboardPreferences::for(auth()->user()));
    }

    private function persist(): void
    {
        $this->prefs = DashboardPreferences::save(auth()->user(), $this->prefs);
    }

    public function updatedPreset(): void
    {
        if ($this->preset === 'custom') {
            $this->start ??= now(\App\Support\BusinessDate::timezone())->startOfMonth()->toDateString();
            $this->end ??= \App\Support\BusinessDate::today();
        }
        $this->savePeriod();
    }

    public function updatedStart(): void { $this->savePeriod(); }

    public function updatedEnd(): void { $this->savePeriod(); }

    private function savePeriod(): void
    {
        $period = ReportPeriod::fromInput($this->preset, $this->start, $this->end);
        $this->preset = $period->preset;
        if ($period->preset === 'custom') {
            $this->start = $period->start->toDateString();
            $this->end = $period->end->toDateString();
        }
        $this->current();
        $this->prefs['period'] = ['preset' => $this->preset, 'start' => $this->start, 'end' => $this->end];
        $this->persist();
    }

    public function toggleCustomize(): void
    {
        $this->customizing = ! $this->customizing;
    }

    public function toggleWidget(string $key): void
    {
        if (! isset(DashboardPreferences::WIDGETS[$key])) {
            return;
        }
        $this->current();
        $hidden = $this->prefs['hidden'];
        $this->prefs['hidden'] = in_array($key, $hidden, true) ? array_values(array_diff($hidden, [$key])) : [...$hidden, $key];
        $this->persist();
    }

    public function moveWidget(string $key, int $delta): void
    {
        $this->current();
        $this->prefs = DashboardPreferences::move($this->prefs, $key, $delta > 0 ? 1 : -1);
        $this->persist();
    }

    public function moveWidgetTo(string $key, string $target): void
    {
        $this->current();
        $this->prefs = DashboardPreferences::moveTo($this->prefs, $key, $target);
        $this->persist();
    }

    public function reorder(array $keys): void
    {
        $this->current();
        $this->prefs['order'] = array_values(array_filter($keys, 'is_string'));
        $this->persist();
    }

    public function setChartType(string $key, string $type): void
    {
        $this->current();
        $this->prefs['charts'][$key] = $type;
        $this->persist();
    }

    public function resetDefaults(): void
    {
        $period = $this->prefs['period'];
        $this->prefs = DashboardPreferences::defaults();
        $this->prefs['period'] = $period;
        $this->persist();
        session()->flash('status', 'Layout padrão restaurado. Nenhum dado financeiro foi alterado.');
    }

    public function refresh(DashboardSnapshotService $service): void
    {
        $service->refresh();
        session()->flash('status', 'Dashboard atualizado.');
    }

    public function with(DashboardSnapshotService $service, DashboardWidgetService $widgets): array
    {
        $snapshot = $service->current();
        $period = ReportPeriod::fromInput($this->preset, $this->start, $this->end);

        return [
            'snapshot' => $snapshot,
            'generatedAt' => $snapshot['generated_at'] ?? null,
            'w' => $widgets->build($period),
        ];
    }
}; ?>

<x-slot name="header">Dashboard Consolidado</x-slot>

@php
    $money = fn ($v, $d = 2) => 'R$ '.number_format((float) $v, $d, ',', '.');
    $tone = fn ($v) => (float) $v >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500';
    $posLabel = 'Posição em '.\Illuminate\Support\Carbon::parse($w['period']['position_date'])->format('d/m/Y');
    $label = 'text-fs-12 text-cryptex-text-tertiary uppercase tracking-[0.05em] font-medium';
    $num = 'font-mono [font-variant-numeric:tabular-nums]';
    $wide = ['cash_position', 'patrimony', 'income_expense', 'accounts', 'own_result'];
    $order = $prefs['order'];
    $hidden = $prefs['hidden'];
    $chart = fn ($k) => \App\Domains\Dashboard\Support\DashboardPreferences::chartType($prefs, $k);
    $allowed = fn ($k) => \App\Domains\Dashboard\Support\DashboardPreferences::WIDGETS[$k][1];
    $title = fn ($k) => \App\Domains\Dashboard\Support\DashboardPreferences::WIDGETS[$k][0];
@endphp

{{-- O componente é #[Lazy]: o mount roda no POST do Livewire e não enxerga a query. Por isso ?personalizar=1 também é lido aqui, após carregar, e removido da URL. --}}
<div class="flex flex-col gap-space-5" data-open-customize
     x-data
     x-init="const q = new URLSearchParams(window.location.search); if (q.get('personalizar') === '1') { q.delete('personalizar'); history.replaceState(null, '', window.location.pathname + (q.toString() ? '?' + q : '')); if (! $wire.customizing) $wire.set('customizing', true); }">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    {{-- Filtro de período --}}
    <x-fx.card>
        <div class="flex flex-col lg:flex-row lg:items-end gap-space-4">
            <div class="flex flex-col gap-1">
                <label for="dash-preset" class="text-fs-12 font-medium text-cryptex-text-tertiary">Período</label>
                <select id="dash-preset" wire:model.live="preset" class="h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-3 text-fs-14 text-cryptex-text-primary">
                    @foreach (\App\Domains\Dashboard\Support\ReportPeriod::PRESETS as $key => $name)
                        <option value="{{ $key }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($preset === 'custom')
                <div class="flex gap-space-3">
                    <div class="flex flex-col gap-1">
                        <label for="dash-start" class="text-fs-12 font-medium text-cryptex-text-tertiary">De</label>
                        <input id="dash-start" type="date" wire:model.live.debounce.600ms="start" class="h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-3 text-fs-14 text-cryptex-text-primary" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="dash-end" class="text-fs-12 font-medium text-cryptex-text-tertiary">Até</label>
                        <input id="dash-end" type="date" wire:model.live.debounce.600ms="end" class="h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-3 text-fs-14 text-cryptex-text-primary" />
                    </div>
                </div>
            @endif
            <livewire:banking.regime-switch />
            <div class="lg:ml-auto flex flex-wrap items-center gap-space-3">
                <button type="button" wire:click="toggleCustomize" aria-pressed="{{ $customizing ? 'true' : 'false' }}" class="min-h-[40px] px-3 rounded-sm border border-cryptex-border-default text-fs-12 font-semibold {{ $customizing ? 'bg-cryptex-brand-400 text-white' : 'text-cryptex-text-primary' }}">
                    {{ $customizing ? 'Concluir personalização' : 'Personalizar' }}
                </button>
                <button type="button" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-semibold text-fs-12" wire:click="refresh">↻ Atualizar agora</button>
            </div>
        </div>
        <div class="mt-space-3 text-fs-12 text-cryptex-text-secondary">
            Fluxos: <strong>{{ $w['period']['label'] }}</strong>.
            Saldos e posições: <strong>{{ $posLabel }}</strong>{{ $w['period']['position_is_today'] ? ' (hoje)' : ' (último dia do período)' }}.
            <span class="text-cryptex-text-tertiary">Regime: {{ $w['regime'] === 'accrual' ? 'Competência' : 'Caixa' }}. Patrimônio, investimentos, sociedade e requisitórios por etapa: posição atual ({{ $generatedAt ? \Illuminate\Support\Carbon::parse($generatedAt)->setTimezone(\App\Support\BusinessDate::timezone())->format('d/m/Y H:i') : '—' }}).</span>
        </div>
    </x-fx.card>

    @if ($customizing)
        <x-fx.card>
            <div class="flex flex-col md:flex-row md:items-center gap-space-3 text-fs-12 text-cryptex-text-secondary">
                <p class="flex-1">Arraste os blocos pelo ⠿ (computador) ou use ↑ ↓ (celular e teclado). Marque “Mostrar” para exibir ou ocultar. Tudo é salvo automaticamente para o seu usuário.</p>
                <button type="button" wire:click="resetDefaults" wire:confirm="Restaurar a ordem, a visibilidade e os tipos de gráfico padrão? Nenhum dado financeiro será alterado." class="min-h-[40px] px-3 rounded-sm border border-cryptex-border-default text-cryptex-text-primary font-semibold">Restaurar padrão</button>
            </div>
        </x-fx.card>
    @endif

    {{-- Arrastar (HTML5): o handle inicia o arraste; o bloco inteiro é alvo de soltura. A ordem é calculada e salva no servidor (moveWidgetTo). --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-5"
         x-data="{ dragging: null, over: null }"
         x-on:dragend.window="dragging = null; over = null">
        @foreach ($order as $index => $key)
            @php $isHidden = in_array($key, $hidden, true); @endphp
            @continue($isHidden && ! $customizing)
            <section data-widget="{{ $key }}" wire:key="widget-{{ $key }}" aria-label="{{ $title($key) }}"
                class="min-w-0 {{ in_array($key, $wide, true) ? 'lg:col-span-2' : '' }} {{ $isHidden ? 'opacity-50' : '' }} {{ $customizing ? 'outline outline-1 outline-dashed outline-cryptex-border-default rounded-md' : '' }}"
                @if ($customizing)
                    x-on:dragover.prevent="if (dragging) { $event.dataTransfer.dropEffect = 'move'; over = '{{ $key }}' }"
                    x-on:dragleave="if (over === '{{ $key }}') over = null"
                    x-on:drop.prevent="const from = dragging || $event.dataTransfer.getData('text/plain'); dragging = null; over = null; if (from && from !== '{{ $key }}') $wire.moveWidgetTo(from, '{{ $key }}')"
                    x-bind:class="over === '{{ $key }}' && dragging !== '{{ $key }}' ? 'ring-2 ring-cryptex-brand-400' : ''"
                @endif>
                @if ($customizing)
                    <div class="flex flex-wrap items-center gap-space-2 px-space-3 py-space-2 text-fs-12">
                        <span draggable="true" data-drag-handle="{{ $key }}" title="Arraste para reordenar"
                            x-on:dragstart="dragging = '{{ $key }}'; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $key }}'); $event.dataTransfer.setDragImage($el.closest('section'), 20, 20)"
                            class="cursor-grab active:cursor-grabbing select-none text-fs-18 leading-none px-2 py-1 rounded-sm border border-cryptex-border-default text-cryptex-text-tertiary hidden md:inline-block" aria-hidden="true">⠿</span>
                        <span class="font-semibold text-cryptex-text-primary">{{ $title($key) }}</span>
                        <label class="flex items-center gap-1 ml-auto text-cryptex-text-secondary">
                            <input type="checkbox" {{ $isHidden ? '' : 'checked' }} wire:click="toggleWidget('{{ $key }}')" aria-label="Mostrar {{ $title($key) }}" /> Mostrar
                        </label>
                        <button type="button" wire:click="moveWidget('{{ $key }}', -1)" @disabled($index === 0) class="min-w-[36px] min-h-[36px] rounded-sm border border-cryptex-border-default disabled:opacity-30" aria-label="Mover {{ $title($key) }} para cima">↑</button>
                        <button type="button" wire:click="moveWidget('{{ $key }}', 1)" @disabled($index === count($order) - 1) class="min-w-[36px] min-h-[36px] rounded-sm border border-cryptex-border-default disabled:opacity-30" aria-label="Mover {{ $title($key) }} para baixo">↓</button>
                    </div>
                @endif
                @if (! $isHidden)
                @switch($key)
                    @case('cash_position')
                        <div class="flex flex-col gap-space-2">
                            <div class="{{ $label }}">Saldo bruto, terceiros e caixa próprio · {{ $posLabel }}</div>
                            <x-banking.cash-position :accounts-balance="$w['cash']['accounts_balance']" :third-party-balance="$w['cash']['third_party_balance']" :own-balance="$w['cash']['own_balance']" />
                        </div>
                        @break

                    @case('own_result')
                        @php $cmp = $w['flows']['comparison']; @endphp
                        <x-fx.card>
                            <div class="grid grid-cols-1 sm:grid-cols-4 gap-space-4">
                                <div><div class="{{ $label }}">Receitas próprias</div><div class="text-fs-20 font-bold {{ $num }} text-cryptex-green-500">{{ $money($w['flows']['income']) }}</div></div>
                                <div><div class="{{ $label }}">Despesas próprias</div><div class="text-fs-20 font-bold {{ $num }} text-cryptex-red-500">{{ $money($w['flows']['expense']) }}</div></div>
                                <div><div class="{{ $label }}">Resultado próprio</div><div class="text-fs-24 font-bold {{ $num }} {{ $tone($w['flows']['result']) }}">{{ $money($w['flows']['result']) }}</div></div>
                                <div>
                                    <div class="{{ $label }}">x período anterior</div>
                                    <div class="text-fs-14 {{ $num }} {{ $tone($cmp['diff']) }}">{{ $cmp['diff'] >= 0 ? '+' : '' }}{{ $money($cmp['diff']) }} · {{ $cmp['pct'] === null ? 'sem base' : ($cmp['pct'] >= 0 ? '+' : '').number_format($cmp['pct'], 1, ',', '.').'%' }}</div>
                                    <div class="text-fs-12 text-cryptex-text-tertiary">Anterior: {{ $money($cmp['previous']) }}</div>
                                </div>
                            </div>
                            <p class="mt-space-2 text-fs-12 text-cryptex-text-tertiary">Transferências, repasses, adiantamentos e principal emprestado/devolvido não entram. Juros recebidos entram.</p>
                        </x-fx.card>
                        @break

                    @case('income_expense')
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-3">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Entradas e saídas próprias</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="income_expense" :current="$chart('income_expense')" :allowed="$allowed('income_expense')" /></div>
                            </div>
                            <x-dashboard.chart :type="$chart('income_expense')" title="Entradas e saídas próprias por mês" :labels="array_column($w['flows']['months'], 'label')"
                                :datasets="[['name' => 'Entradas', 'values' => array_column($w['flows']['months'], 'income'), 'color' => '#22c55e'], ['name' => 'Saídas', 'values' => array_column($w['flows']['months'], 'expense'), 'color' => '#ef4444'], ['name' => 'Resultado', 'values' => array_column($w['flows']['months'], 'result'), 'color' => '#60a5fa']]" />
                        </x-fx.card>
                        @break

                    @case('moving_averages')
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-3">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Médias móveis (resultado)</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="moving_averages" :current="$chart('moving_averages')" :allowed="$allowed('moving_averages')" /></div>
                            </div>
                            <div class="grid grid-cols-3 gap-space-2 mb-space-3">
                                @foreach (['last_3' => '3m', 'last_6' => '6m', 'last_12' => '12m'] as $k => $l)
                                    <div><div class="text-fs-12 text-cryptex-text-tertiary {{ $num }}">{{ $l }}</div><div class="font-medium {{ $num }} {{ $tone($w['averages'][$k]) }}">{{ $money($w['averages'][$k], 0) }}</div></div>
                                @endforeach
                            </div>
                            <x-dashboard.chart :type="$chart('moving_averages')" title="Resultado mensal e média móvel de 3 meses" :labels="array_column($w['averages']['series'], 'label')"
                                :datasets="[['name' => 'Resultado do mês', 'values' => array_column($w['averages']['series'], 'result'), 'color' => '#94a3b8'], ['name' => 'Média 3m', 'values' => array_column($w['averages']['series'], 'avg3'), 'color' => '#f59e0b']]" />
                        </x-fx.card>
                        @break

                    @case('invoices')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">💳 Faturas · {{ $posLabel }}</h3>
                            <div class="grid grid-cols-2 gap-space-3 my-space-3">
                                <div><div class="{{ $label }}">A vencer ({{ $w['invoices']['upcoming_count'] }})</div><div class="text-fs-18 font-bold {{ $num }} text-cryptex-text-primary">{{ $money($w['invoices']['upcoming_total']) }}</div></div>
                                <div><div class="{{ $label }}">Vencidas ({{ $w['invoices']['overdue_count'] }})</div><div class="text-fs-18 font-bold {{ $num }} text-cryptex-red-500">{{ $money($w['invoices']['overdue_total']) }}</div></div>
                            </div>
                            <ul class="flex flex-col text-fs-12">
                                @foreach ($w['invoices']['list'] as $row)
                                    <li class="flex justify-between py-1 border-b border-cryptex-border-subtle last:border-0">
                                        <span class="text-cryptex-text-secondary">{{ $row['card'] }} · {{ $row['reference_month'] }} · venc. {{ $row['due_date'] ? \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') : '—' }} @if ($row['overdue'])<strong class="text-cryptex-red-500">vencida</strong>@endif</span>
                                        <span class="{{ $num }} text-cryptex-text-primary">{{ $money($row['remaining']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ route('banking.cards.index') }}" class="inline-block mt-space-2 text-fs-12 text-cryptex-brand-400 font-semibold">Abrir cartões e faturas →</a>
                        </x-fx.card>
                        @break

                    @case('receivables')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Valores a receber · {{ $posLabel }}</h3>
                            <div class="grid grid-cols-3 gap-space-3 mt-space-3">
                                <div><div class="{{ $label }}">Em aberto</div><div class="text-fs-18 font-bold {{ $num }}">{{ $w['receivables']['count'] }}</div></div>
                                <div><div class="{{ $label }}">Principal</div><div class="text-fs-14 font-bold {{ $num }}">{{ $money($w['receivables']['principal']) }}</div></div>
                                <div><div class="{{ $label }}">Com encargos</div><div class="text-fs-14 font-bold {{ $num }} text-cryptex-green-500">{{ $money($w['receivables']['total']) }}</div></div>
                            </div>
                            <a href="{{ route('banking.receivables.index') }}" class="inline-block mt-space-2 text-fs-12 text-cryptex-brand-400 font-semibold">Abrir Valores a receber →</a>
                        </x-fx.card>
                        @break

                    @case('loans')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Empréstimos em aberto · {{ $posLabel }}</h3>
                            <div class="grid grid-cols-3 gap-space-3 mt-space-3">
                                <div><div class="{{ $label }}">Contratos</div><div class="text-fs-18 font-bold {{ $num }}">{{ $w['loans']['count'] }}@if ($w['loans']['overdue']) <span class="text-fs-12 text-cryptex-red-500">({{ $w['loans']['overdue'] }} vencido{{ $w['loans']['overdue'] > 1 ? 's' : '' }})</span>@endif</div></div>
                                <div><div class="{{ $label }}">Principal</div><div class="text-fs-14 font-bold {{ $num }}">{{ $money($w['loans']['principal']) }}</div></div>
                                <div><div class="{{ $label }}">Com juros</div><div class="text-fs-14 font-bold {{ $num }} text-cryptex-green-500">{{ $money($w['loans']['total']) }}</div></div>
                            </div>
                            <a href="{{ route('banking.loans.index') }}" class="inline-block mt-space-2 text-fs-12 text-cryptex-brand-400 font-semibold">Abrir Empréstimos →</a>
                        </x-fx.card>
                        @break

                    @case('brokers')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Saldo com corretores · posição atual</h3>
                            <div class="grid grid-cols-2 gap-space-3 mt-space-3">
                                <div><div class="{{ $label }}">Adiantamentos a compensar</div><div class="text-fs-16 font-bold {{ $num }}">{{ $money($w['brokers']['advances']) }}</div></div>
                                <div><div class="{{ $label }}">Comissões a pagar</div><div class="text-fs-16 font-bold {{ $num }} text-cryptex-red-500">{{ $money($w['brokers']['commissions']) }}</div></div>
                            </div>
                            <div class="text-fs-12 text-cryptex-text-tertiary mt-space-1">{{ $w['brokers']['count'] }} corretor(es) com saldo.</div>
                            <a href="{{ route('brokers.index') }}" class="inline-block mt-space-2 text-fs-12 text-cryptex-brand-400 font-semibold">Abrir Corretores →</a>
                        </x-fx.card>
                        @break

                    @case('writs_upcoming')
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-2">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">⚖️ Requisitórios a receber (6 meses)</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="writs_upcoming" :current="$chart('writs_upcoming')" :allowed="$allowed('writs_upcoming')" /></div>
                            </div>
                            <div class="text-fs-12 text-cryptex-text-tertiary mb-space-2">Somente Pago, Peticionamento e Aguardando recebimento, pela previsão (pagamento + meses estimados). Monitorar nunca entra.</div>
                            <div class="text-fs-14 mb-space-2">Previsto: <strong class="{{ $num }}">{{ $money($w['writs_upcoming']['total']) }}</strong>
                                @if ($w['writs_upcoming']['overdue']['count']) · <span class="text-cryptex-red-500">{{ $w['writs_upcoming']['overdue']['count'] }} com previsão vencida: {{ $money($w['writs_upcoming']['overdue']['amount']) }} (fora do total)</span>@endif
                                @if ($w['writs_upcoming']['without_date']) · <span class="text-cryptex-text-tertiary">{{ $w['writs_upcoming']['without_date'] }} sem previsão</span>@endif
                            </div>
                            <x-dashboard.chart :type="$chart('writs_upcoming')" title="Recebimento previsto de requisitórios por mês" :labels="array_column($w['writs_upcoming']['months'], 'label')"
                                :datasets="[['name' => 'Recebimento previsto', 'values' => array_column($w['writs_upcoming']['months'], 'amount'), 'color' => '#60a5fa'], ['name' => 'Lucro previsto', 'values' => array_column($w['writs_upcoming']['months'], 'profit'), 'color' => '#22c55e']]" />
                        </x-fx.card>
                        @break

                    @case('expenses_by_category')
                        @php $cats = array_slice($w['expenses_by_category'], 0, 9); $rest = array_sum(array_column(array_slice($w['expenses_by_category'], 9), 'value')); if ($rest > 0) { $cats[] = ['label' => 'Outras', 'value' => round($rest, 2)]; } @endphp
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-3">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Despesas próprias por categoria</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="expenses_by_category" :current="$chart('expenses_by_category')" :allowed="$allowed('expenses_by_category')" /></div>
                            </div>
                            @if ($chart('expenses_by_category') === 'pie')
                                <x-dashboard.chart type="pie" title="Despesas próprias por categoria" :labels="array_column($cats, 'label')" :datasets="[['name' => 'Despesa', 'values' => array_column($cats, 'value')]]" />
                            @else
                                <ul class="flex flex-col gap-space-2" aria-label="Despesas próprias por categoria">
                                    @php $maxCat = max(array_column($cats, 'value') ?: [1]); @endphp
                                    @forelse ($cats as $c)
                                        <li>
                                            <div class="flex justify-between text-fs-12"><span class="text-cryptex-text-secondary truncate">{{ $c['label'] }}</span><span class="{{ $num }} text-cryptex-text-primary">{{ $money($c['value']) }}</span></div>
                                            <div class="h-2 bg-cryptex-bg-tertiary rounded-full overflow-hidden" aria-hidden="true"><div class="h-full bg-cryptex-red-500" style="width: {{ $maxCat > 0 ? round($c['value'] / $maxCat * 100, 1) : 0 }}%"></div></div>
                                        </li>
                                    @empty
                                        <li class="text-fs-12 text-cryptex-text-tertiary">Sem despesas próprias no período.</li>
                                    @endforelse
                                </ul>
                            @endif
                        </x-fx.card>
                        @break

                    @case('accounts')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold mb-space-3 text-cryptex-text-primary">Saldos por conta · {{ $posLabel }}</h3>
                            <div class="{{ $label }} mb-space-2">Contas próprias</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-space-4">
                                @forelse ($w['accounts']['own'] as $row)
                                    <div class="flex justify-between items-center py-space-2 border-b border-cryptex-border-subtle"><span class="text-fs-14 text-cryptex-text-secondary">{{ $row['name'] }}</span><span class="font-medium {{ $num }} {{ $tone($row['balance']) }}">{{ $money($row['balance']) }}</span></div>
                                @empty
                                    <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma conta ativa.</div>
                                @endforelse
                            </div>
                            @if (count($w['accounts']['repasse']))
                                <div class="{{ $label }} mt-space-4 mb-space-2">Contas de repasse (dinheiro de terceiros)</div>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-x-space-4">
                                    @foreach ($w['accounts']['repasse'] as $row)
                                        <div class="flex justify-between items-center py-space-2 border-b border-cryptex-border-subtle"><span class="text-fs-14 text-cryptex-text-secondary">{{ $row['name'] }}</span><span class="font-medium {{ $num }} text-cryptex-text-primary">{{ $money($row['balance']) }}</span></div>
                                    @endforeach
                                </div>
                            @endif
                        </x-fx.card>
                        @break

                    @case('patrimony')
                        <x-fx.card>
                            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-space-5">
                                <div>
                                    <div class="{{ $label }}">Patrimônio total consolidado · posição atual</div>
                                    <div class="text-fs-32 font-bold text-cryptex-text-primary {{ $num }}">{{ $money($snapshot['patrimony']['total']) }}</div>
                                </div>
                                <div class="grid grid-cols-2 md:grid-cols-5 gap-space-3 text-fs-12 text-cryptex-text-secondary md:flex-1 md:max-w-2xl">
                                    @foreach ([['Saldo próprio', $snapshot['patrimony']['own_balance'] ?? $snapshot['patrimony']['cash_balance']], ['Carteira', $snapshot['patrimony']['portfolio_market_value']], ['Sociedade', $snapshot['patrimony']['partnership_exposed']], ['Requisitórios', $snapshot['patrimony']['writs_capital_at_risk']], ['(−) Faturas', $snapshot['patrimony']['open_invoices_total']]] as [$l, $v])
                                        <div><div class="uppercase tracking-[0.05em] text-[10px]">{{ $l }}</div><div class="font-medium text-cryptex-text-primary text-fs-14 {{ $num }}">{{ $money($v) }}</div></div>
                                    @endforeach
                                </div>
                            </div>
                            <p class="mt-space-2 text-fs-12 text-cryptex-text-tertiary">Inclui Bens ativos ({{ $money($snapshot['patrimony']['properties'] ?? 0) }}). @if (str_starts_with($snapshot['patrimony']['portfolio_source'] ?? '', 'b3:'))Carteira pela posição B3 de {{ \Illuminate\Support\Carbon::parse(substr($snapshot['patrimony']['portfolio_source'], 3))->format('d/m/Y') }}.@endif</p>
                        </x-fx.card>
                        @break

                    @case('distribution')
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-3">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Distribuição do patrimônio</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="distribution" :current="$chart('distribution')" :allowed="$allowed('distribution')" /></div>
                            </div>
                            @php $dist = collect($snapshot['distribution'])->values()->all(); @endphp
                            <x-dashboard.chart :type="$chart('distribution')" title="Distribuição do patrimônio" :labels="array_column($dist, 'label')" :datasets="[['name' => 'Valor', 'values' => array_column($dist, 'value'), 'color' => '#60a5fa']]" />
                        </x-fx.card>
                        @break

                    @case('investments')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">📈 Investimentos</h3>
                            <div class="grid grid-cols-3 gap-space-2 text-fs-14 mb-space-4">
                                <div><div class="text-fs-12 text-cryptex-text-secondary">Investido</div><div class="font-medium {{ $num }}">{{ $money($snapshot['investments']['invested']) }}</div></div>
                                <div><div class="text-fs-12 text-cryptex-text-secondary">Mercado</div><div class="font-medium {{ $num }}">{{ $money($snapshot['investments']['market_value']) }}</div></div>
                                <div><div class="text-fs-12 text-cryptex-text-secondary">Proventos 12m</div><div class="font-medium {{ $num }} text-cryptex-green-500">{{ $money($snapshot['investments']['dividends_12m']) }}</div></div>
                            </div>
                            <ul class="flex flex-col gap-space-1 text-fs-12">
                                @foreach ($snapshot['investments']['by_class'] ?? [] as $class => $row)
                                    <li class="flex justify-between py-1 border-b border-cryptex-border-subtle last:border-0"><span class="text-cryptex-text-secondary">{{ $class }}</span><span class="{{ $num }}">{{ $money($row['market_value']) }}</span></li>
                                @endforeach
                            </ul>
                        </x-fx.card>
                        @break

                    @case('writs')
                        <x-fx.card>
                            <div class="flex flex-wrap items-center gap-space-3 mb-space-3">
                                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">⚖️ Requisitórios por etapa</h3>
                                <div class="ml-auto"><x-dashboard.chart-switch widget="writs" :current="$chart('writs')" :allowed="$allowed('writs')" /></div>
                            </div>
                            <div class="grid grid-cols-2 gap-space-2 text-fs-14 mb-space-3">
                                <div><div class="text-fs-12 text-cryptex-text-secondary">Capital em risco</div><div class="font-medium {{ $num }}">{{ $money($snapshot['writs']['capital_at_risk']) }}</div></div>
                                <div><div class="text-fs-12 text-cryptex-text-secondary">Lucro líquido exp.</div><div class="font-medium {{ $num }} text-cryptex-green-500">{{ $money($snapshot['writs']['expected_net_profit']) }}</div></div>
                            </div>
                            @php $stages = collect($snapshot['writs']['by_stage'])->filter(fn ($r) => $r['count'] > 0)->values()->all(); @endphp
                            <x-dashboard.chart :type="$chart('writs')" title="Valor de face por etapa" :labels="array_column($stages, 'label')" :datasets="[['name' => 'Valor de face', 'values' => array_column($stages, 'face_total'), 'color' => '#a78bfa']]" />
                            <ul class="flex flex-col gap-space-1 text-fs-12 mt-space-2">
                                @foreach ($stages as $row)
                                    <li class="flex justify-between py-1 border-b border-cryptex-border-subtle last:border-0"><span class="text-cryptex-text-secondary">{{ $row['label'] }}</span><span class="{{ $num }}"><span class="text-cryptex-brand-400 mr-2">{{ $row['count'] }}x</span>{{ $money($row['face_total'], 0) }}</span></li>
                                @endforeach
                            </ul>
                        </x-fx.card>
                        @break

                    @case('partnerships')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">🏢 Sociedade</h3>
                            @forelse ($snapshot['partnerships']['list'] as $row)
                                <div class="flex justify-between py-space-1 border-b border-cryptex-border-subtle last:border-0 text-fs-14">
                                    <span>{{ $row['name'] }}</span>
                                    <span class="{{ $num }}"><span class="text-[10px] text-cryptex-text-tertiary uppercase">exp.</span> {{ $money($row['exposed']) }} <span class="ml-space-2 font-medium {{ $tone($row['net_result']) }}">{{ $row['net_result'] >= 0 ? '+' : '' }}{{ number_format($row['net_result'], 0, ',', '.') }}</span></span>
                                </div>
                            @empty
                                <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma sociedade ativa.</div>
                            @endforelse
                            @if (! empty($snapshot['future_contributions']))
                                <div class="text-[10px] text-cryptex-text-tertiary uppercase mt-space-4 mb-space-2 font-medium">Aportes futuros</div>
                                @foreach ($snapshot['future_contributions'] as $f)
                                    <div class="flex justify-between py-1 text-fs-12"><span class="text-cryptex-text-secondary">{{ $f['partnership'] }} · {{ \Illuminate\Support\Carbon::parse($f['date'])->format('d/m/Y') }}</span><span class="{{ $num }}">{{ $money($f['amount']) }}</span></div>
                                @endforeach
                            @endif
                        </x-fx.card>
                        @break

                    @case('upcoming_invoices')
                        <x-fx.card>
                            <h3 class="text-fs-16 font-semibold mb-space-4 text-cryptex-text-primary">💳 Faturas próximas (30 dias)</h3>
                            @forelse ($snapshot['upcoming_invoices'] as $row)
                                <div class="flex justify-between items-center py-space-1 border-b border-cryptex-border-subtle last:border-0 text-fs-14">
                                    <div><div>{{ $row['card'] }}</div><div class="text-fs-12 text-cryptex-text-secondary">{{ $row['reference_month'] }} · venc. {{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d/m/Y') }}</div></div>
                                    <div class="font-medium {{ $num }} text-cryptex-red-500">{{ $money($row['remaining']) }}</div>
                                </div>
                            @empty
                                <div class="text-fs-14 text-cryptex-text-secondary">Nenhuma fatura em aberto.</div>
                            @endforelse
                        </x-fx.card>
                        @break
                @endswitch
                @endif
            </section>
        @endforeach
    </div>

    {{-- Atalhos rápidos: "Lançamento" abre o modal único (inclui "Paguei por alguém"). --}}
    <div class="flex flex-wrap gap-space-3 mt-space-2">
        <x-fx.button href="{{ route('banking.transactions.index', ['novo' => 'expense']) }}" variant="primary">Lançamento</x-fx.button>
        <x-fx.button href="{{ route('banking.transactions.index', ['novo' => 'transfer']) }}" variant="secondary">Transferência</x-fx.button>
        <x-fx.button href="{{ route('investments.operations.index') }}" variant="secondary">Operação</x-fx.button>
        <x-fx.button href="{{ route('writs.create') }}" variant="secondary">Requisitório</x-fx.button>
    </div>
</div>

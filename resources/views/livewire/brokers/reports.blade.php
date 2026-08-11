<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $month;

    public string $year;

    public string $broker_id = 'all';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public function mount(): void
    {
        $this->month = (string) now()->month;
        $this->year = (string) now()->year;
    }

    public function clearCustomPeriod(): void
    {
        $this->start_date = null;
        $this->end_date = null;
    }

    private function selectedDateRange(): array
    {
        if ($this->start_date || $this->end_date) {
            return [$this->start_date, $this->end_date];
        }

        $year = (int) $this->year;

        if ($this->month === 'all') {
            return [
                Carbon::create($year, 1, 1)->startOfYear()->toDateString(),
                Carbon::create($year, 12, 1)->endOfYear()->toDateString(),
            ];
        }

        $date = Carbon::create($year, (int) $this->month, 1);

        return [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()];
    }

    private function applyFilters(Builder|Relation $query, string $dateColumn): Builder|Relation
    {
        [$startDate, $endDate] = $this->selectedDateRange();

        return $query
            ->when($startDate, fn ($query) => $query->whereDate($dateColumn, '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate($dateColumn, '<=', $endDate))
            ->when($this->broker_id !== 'all', fn ($query) => $query->where('broker_id', $this->broker_id));
    }

    public function with(): array
    {
        $queryCommissions = $this->applyFilters(BrokerCommission::query(), 'reference_date');
        $queryAdvances = $this->applyFilters(BrokerAdvance::query(), 'date');

        // Resumo geral
        $totalCommissions = (clone $queryCommissions)->sum('commission_amount');
        $totalPendingCommissions = (clone $queryCommissions)->where('status', 'pending')->sum('commission_amount');
        $totalAdvances = (clone $queryAdvances)->sum('amount');

        // Comissões pagas no período
        $paidCommissions = (clone $queryCommissions)->whereIn('status', ['paid', 'partially_paid'])
            ->with('broker', 'caseType')
            ->orderByDesc('reference_date')
            ->get();

        // Adiantamentos no período
        $advances = (clone $queryAdvances)->with('broker')->orderByDesc('date')->get();
        $openAdvancesBalance = $advances->sum(fn ($a) => $a->remainingBalance());

        // Por corretor (apenas comissões no período)
        $brokers = Broker::query()
            ->when($this->broker_id !== 'all', fn (Builder $query) => $query->whereKey($this->broker_id))
            ->with(['commissions' => fn ($query) => $this->applyFilters($query, 'reference_date')])
            ->orderBy('name')
            ->get()
            ->filter(fn (Broker $broker) => $broker->commissions->isNotEmpty());

        $brokerOptions = Broker::query()->orderBy('name')->get(['id', 'name']);
        $years = range(now()->year - 3, now()->year + 2);

        return compact(
            'totalCommissions', 'totalPendingCommissions',
            'totalAdvances', 'openAdvancesBalance',
            'paidCommissions', 'advances', 'brokers',
            'brokerOptions', 'years'
        );
    }
}; ?>

<x-slot name="header">Relatórios de Corretores</x-slot>

<div class="flex flex-col gap-md">
    <x-brokers.subnav />

    <x-fx.card>
        <div class="flex flex-col gap-md">
            <div>
                <div class="text-sm font-semibold text-mono-900">Filtros do relatório</div>
                <div class="mt-xxs text-xs text-mono-600">Selecione mês, ano e corretor ou informe um período personalizado.</div>
            </div>

            <div class="grid grid-cols-1 gap-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Mês</label>
                    <select wire:model.live="month" class="fx-form-field">
                        <option value="all">Todos os meses</option>
                        <option value="1">Janeiro</option>
                        <option value="2">Fevereiro</option>
                        <option value="3">Março</option>
                        <option value="4">Abril</option>
                        <option value="5">Maio</option>
                        <option value="6">Junho</option>
                        <option value="7">Julho</option>
                        <option value="8">Agosto</option>
                        <option value="9">Setembro</option>
                        <option value="10">Outubro</option>
                        <option value="11">Novembro</option>
                        <option value="12">Dezembro</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Ano</label>
                    <select wire:model.live="year" class="fx-form-field">
                        @foreach ($years as $availableYear)
                            <option value="{{ $availableYear }}">{{ $availableYear }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block text-xxs text-mono-600 mb-xxxs">Corretor</label>
                    <select wire:model.live="broker_id" class="fx-form-field">
                        <option value="all">Todos os corretores</option>
                        @foreach ($brokerOptions as $brokerOption)
                            <option value="{{ $brokerOption->id }}">{{ $brokerOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="border-t border-mono-200 pt-md">
                <div class="flex flex-col gap-sm lg:flex-row lg:items-end lg:justify-between">
                    <div class="grid flex-1 grid-cols-1 gap-sm sm:grid-cols-2 lg:max-w-[640px]">
                        <x-fx.input label="Data inicial" type="date" wire:model.live="start_date" />
                        <x-fx.input label="Data final" type="date" wire:model.live="end_date" />
                    </div>

                    @if ($start_date || $end_date)
                        <button type="button" wire:click="clearCustomPeriod" class="fx-btn fx-btn--text fx-btn--sm self-start lg:self-auto">
                            <span class="material-icons-outlined text-base">restart_alt</span>
                            Usar mês e ano
                        </button>
                    @endif
                </div>
                <div class="mt-xs text-xs text-mono-600">
                    Ao preencher uma das datas, o período personalizado substitui o filtro de mês e ano.
                </div>
            </div>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Comissões (Total)</div>
            <div class="text-xl font-bold">R$ {{ number_format($totalCommissions, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Comissões Pendentes</div>
            <div class="text-xl font-bold {{ $totalPendingCommissions > 0 ? 'text-system-down' : '' }}">
                R$ {{ number_format($totalPendingCommissions, 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Adiantamentos (Total)</div>
            <div class="text-xl font-bold">R$ {{ number_format($totalAdvances, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Adiantamentos a Compensar</div>
            <div class="text-xl font-bold text-system-up">
                R$ {{ number_format($openAdvancesBalance, 2, ',', '.') }}
            </div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Comissões Pagas no Período</h3>
            @if ($paidCommissions->isEmpty())
                <div class="text-sm text-mono-600">Nenhuma comissão paga no período selecionado.</div>
            @else
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Data</th><th class="text-left">Corretor</th><th class="text-right">Comissão</th></tr></thead>
                    <tbody>
                        @foreach ($paidCommissions as $com)
                            <tr>
                                <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                                <td>{{ $com->broker->name }}</td>
                                <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fx.card>

        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Resumo por Corretor (No período)</h3>
            @if ($brokers->isEmpty())
                <div class="text-sm text-mono-600">Nenhum corretor com comissões no período selecionado.</div>
            @else
                <table class="fx-table w-full text-sm">
                    <thead><tr><th class="text-left">Corretor</th><th class="text-right">Comissões (Qtd)</th><th class="text-right">Valor Total</th></tr></thead>
                    <tbody>
                        @foreach ($brokers as $b)
                            <tr>
                                <td>
                                    <a href="{{ route('brokers.show', $b) }}" class="font-medium hover:text-primary-500">{{ $b->name }}</a>
                                </td>
                                <td class="text-right">{{ $b->commissions->count() }}</td>
                                <td class="text-right font-semibold">R$ {{ number_format($b->commissions->sum('commission_amount'), 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fx.card>
    </div>
</div>

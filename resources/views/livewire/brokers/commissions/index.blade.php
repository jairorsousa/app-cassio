<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Support\ColumnSorter;
use App\Support\Concerns\SortsColumns;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    use SortsColumns;

    public Broker $broker;
    public bool $showSettleModal = false;
    public ?int $settleCommissionId = null;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->loadSort();
    }

    protected function sortListKey(): string
    {
        return 'broker_commissions_index';
    }

    public function openSettle(int $commissionId): void
    {
        $this->settleCommissionId = $commissionId;
        $this->showSettleModal = true;
    }

    public function closeSettle(): void
    {
        $this->showSettleModal = false;
        $this->settleCommissionId = null;
    }

    public function settleWithAdvances(BrokerCommissionService $service): void
    {
        $commission = BrokerCommission::findOrFail($this->settleCommissionId);
        $settled = $service->settleWithAdvances($commission);

        $this->closeSettle();
        session()->flash('status', 'Compensado R$ '.number_format($settled, 2, ',', '.').' em adiantamentos.');
    }

    public function payCommission(int $id, BrokerCommissionService $service): void
    {
        $commission = BrokerCommission::findOrFail($id);
        $service->pay($commission);
        session()->flash('status', 'Comissão marcada como paga.');
    }

    public function with(): array
    {
        [$by, $dir] = $this->sortState();
        $all = BrokerCommission::where('broker_id', $this->broker->id)->with('caseType', 'settlements.advance')->orderByDesc('reference_date')->get();
        $commissions = ColumnSorter::paginate(ColumnSorter::sort($all, $by, $dir, [
            'date' => fn ($c) => $c->reference_date,
            'case_type' => fn ($c) => $c->caseType?->name ?: '',
            'name' => fn ($c) => $c->name ?: '',
            'base' => fn ($c) => (float) ($c->base_amount ?? 0),
            'percent' => fn ($c) => (float) ($c->percentage_applied ?? 0),
            'amount' => fn ($c) => (float) $c->commission_amount,
            'settled' => fn ($c) => $c->settledAmount(),
            'balance' => fn ($c) => $c->remainingAmount(),
            'status' => fn ($c) => ['pending' => 'Pendente', 'paid' => 'Pago', 'partially_paid' => 'Parcial'][$c->status] ?? $c->status,
        ], $by === 'date' ? [[fn ($c) => $c->id, $dir]] : [[fn ($c) => $c->reference_date, 'desc'], [fn ($c) => $c->id, 'desc']]), $this, 25);

        $settleCommission = $this->settleCommissionId
            ? BrokerCommission::with('settlements.advance')->find($this->settleCommissionId)
            : null;

        return compact('commissions', 'settleCommission');
    }
}; ?>

<x-slot name="header">Comissões · {{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <div class="flex flex-wrap justify-between items-center gap-3">
        <x-back-button :href="route('brokers.show', $broker)" label="Voltar ao corretor" />
        <x-fx.button href="{{ route('brokers.commissions.create', $broker) }}" variant="primary" size="sm">+ Registrar comissão</x-fx.button>
    </div>

    <x-fx.card>
        @if ($commissions->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma comissão registrada.</div>
        @else
            <x-fx.sort-mobile list="broker_commissions_index" :sort-by="$sortBy" :sort-dir="$sortDir" primary />
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <x-fx.sort-th column="date" label="Data ref." :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="case_type" label="Tipo de caso" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="name" label="Nome" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="base" label="Base" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="percent" label="%" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="amount" label="Comissão" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="settled" label="Compensado" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="balance" label="A pagar" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="status" label="Status" align="center" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($commissions as $com)
                        <tr>
                            <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                            <td>{{ $com->caseType->name }}</td>
                            <td class="max-w-[180px] truncate" title="{{ $com->name }}">{{ $com->name ?: '—' }}</td>
                            <td class="text-right">R$ {{ number_format($com->base_amount, 2, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($com->percentage_applied, 1, ',', '.') }}%</td>
                            <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                            <td class="text-right text-system-up">R$ {{ number_format($com->settledAmount(), 2, ',', '.') }}</td>
                            <td class="text-right {{ $com->remainingAmount() > 0 ? 'text-system-down font-semibold' : '' }}">
                                R$ {{ number_format($com->remainingAmount(), 2, ',', '.') }}
                            </td>
                            <td class="text-center">
                                <x-fx.badge :variant="$com->status === 'paid' ? 'up' : ($com->status === 'partially_paid' ? 'neutral' : 'down')">
                                    {{ ['pending' => 'Pendente', 'paid' => 'Pago', 'partially_paid' => 'Parcial'][$com->status] }}
                                </x-fx.badge>
                            </td>
                            <td class="text-right">
                                @if ($com->status !== 'paid')
                                    <div class="flex justify-end gap-xxs">
                                        <button wire:click="openSettle({{ $com->id }})" class="fx-btn fx-btn--text fx-btn--sm">Compensar</button>
                                        <button wire:click="payCommission({{ $com->id }})" class="fx-btn fx-btn--standard fx-btn--sm" wire:confirm="Marcar como paga?">Pagar</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-md">{{ $commissions->links() }}</div>
        @endif
    </x-fx.card>

    {{-- Modal de compensação --}}
    @if ($showSettleModal && $settleCommission)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-mono-900/50" wire:click.self="closeSettle">
            <x-fx.card class="max-w-lg w-full mx-md">
                <h3 class="text-md font-semibold mb-sm">Compensar com adiantamentos</h3>
                <p class="text-sm text-mono-600 mb-sm">
                    Comissão: R$ {{ number_format($settleCommission->commission_amount, 2, ',', '.') }}
                    — Saldo a pagar: R$ {{ number_format($settleCommission->remainingAmount(), 2, ',', '.') }}
                </p>

                @if ($settleCommission->settlements->isNotEmpty())
                    <h4 class="text-sm font-medium mb-xs">Compensações já realizadas:</h4>
                    <ul class="text-sm mb-sm">
                        @foreach ($settleCommission->settlements as $s)
                            <li>{{ $s->advance->date->format('d/m/Y') }} — R$ {{ number_format($s->amount_offset, 2, ',', '.') }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="flex gap-xs">
                    <button wire:click="settleWithAdvances" class="fx-btn fx-btn--primary">Compensar automaticamente</button>
                    <button wire:click="closeSettle" class="fx-btn fx-btn--text">Fechar</button>
                </div>
            </x-fx.card>
        </div>
    @endif
</div>

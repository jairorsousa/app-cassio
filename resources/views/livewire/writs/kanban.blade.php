<?php

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-xs animate-pulse">
            <div class="h-96 bg-mono-100 rounded-md"></div>
            <div class="h-96 bg-mono-100 rounded-md"></div>
            <div class="h-96 bg-mono-100 rounded-md"></div>
            <div class="h-96 bg-mono-100 rounded-md"></div>
            <div class="h-96 bg-mono-100 rounded-md"></div>
        </div>
        HTML;
    }

    #[Url]
    public string $type = '';
    #[Url]
    public string $debtor = '';
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    public function delete(int $id): void
    {
        $writ = Writ::findOrFail($id);
        $writ->transactions()->delete();
        $writ->history()->delete();
        \Spatie\Activitylog\Models\Activity::where('subject_type', Writ::class)
            ->where('subject_id', $writ->id)
            ->delete();
        $writ->forceDelete();
        session()->flash('status', 'Requisitório excluído.');
    }

    public function moveCard(int $writId, string $newStage, WritService $service): void
    {
        try {
            $writ = Writ::findOrFail($writId);
            $service->transitionTo($writ, $newStage);
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS[$newStage].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'debtor', 'from', 'to']);
    }

    public function with(): array
    {
        $q = Writ::query();
        if ($this->type) $q->where('type', $this->type);
        if ($this->debtor) $q->where('debtor_entity', 'like', '%'.$this->debtor.'%');
        if ($this->from) $q->where('paid_at', '>=', $this->from);
        if ($this->to) $q->where('paid_at', '<=', $this->to);

        $writs = $q->orderByDesc('id')->get()->groupBy('stage');

        $stages = [];
        foreach (Writ::STAGES as $stage) {
            $cards = $writs->get($stage, collect());
            $stages[] = [
                'key' => $stage,
                'label' => Writ::STAGE_LABELS[$stage],
                'count' => $cards->count(),
                'face_total' => $cards->sum('face_value'),
                'cards' => $cards,
            ];
        }

        $totalInvested = Writ::whereIn('stage', ['paid', 'petitioning'])->sum('paid_amount');
        $totalReceived = Writ::where('stage', 'finalized')->sum('actual_receipt_amount');

        return compact('stages', 'totalInvested', 'totalReceived');
    }
}; ?>

<x-slot name="header">Requisitórios · Pipeline</x-slot>

<div class="flex flex-col gap-md" x-data="writsKanban(@js(\App\Domains\Writs\Models\Writ::STAGES))">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    {{-- Indicadores --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Total investido em aberto</div>
            <div class="text-xl font-bold text-mono-900">R$ {{ number_format($totalInvested, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Total recebido (finalizados)</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($totalReceived, 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex justify-between items-center">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Operações</div>
                    <div class="text-xl font-bold">{{ collect($stages)->sum('count') }}</div>
                </div>
                <x-fx.button href="{{ route('writs.create') }}" variant="primary" size="sm">+ Novo card</x-fx.button>
            </div>
        </x-fx.card>
    </div>

    {{-- Filtros --}}
    <x-fx.card>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-xs items-end">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model.live="type" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="rpv">RPV</option>
                    <option value="precatorio">Precatório</option>
                </select>
            </div>
            <x-fx.input label="Ente devedor" wire:model.live.debounce.500ms="debtor" placeholder="União, INSS..." />
            <x-fx.input label="Pago de" type="date" wire:model.live="from" />
            <x-fx.input label="Pago até" type="date" wire:model.live="to" />
            <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="clearFilters">Limpar filtros</button>
        </div>
    </x-fx.card>

    @php $hasAnyWrit = collect($stages)->sum('count') > 0; @endphp
    @if (! $hasAnyWrit)
        <x-fx.card>
            <x-fx.empty-state
                icon="⚖️"
                title="Nenhum requisitório no pipeline"
                description="Crie um card e arraste pelas etapas: Negociação → Cessão Pendente → Pago → Peticionar → Finalizar."
                actionLabel="+ Novo requisitório"
                :actionHref="route('writs.create')" />
        </x-fx.card>
    @endif

    {{-- Kanban board --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-xs">
        @foreach ($stages as $stage)
            <div class="bg-mono-50 border border-mono-100 rounded-md flex flex-col min-h-[400px]" data-stage="{{ $stage['key'] }}">
                <div class="px-sm py-xs border-b border-mono-100">
                    <div class="text-xxs uppercase text-mono-600 tracking-wide">{{ $stage['label'] }}</div>
                    <div class="text-sm font-bold">{{ $stage['count'] }} cards</div>
                    <div class="text-xxs text-mono-600">R$ {{ number_format($stage['face_total'], 2, ',', '.') }}</div>
                </div>
                <div class="kanban-list flex-1 p-xs flex flex-col gap-xs" data-stage="{{ $stage['key'] }}">
                    @foreach ($stage['cards'] as $w)
                        <div
                            class="kanban-card bg-mono-white border border-mono-100 rounded-md p-xs cursor-grab"
                            data-id="{{ $w->id }}"
                            wire:key="writ-{{ $w->id }}"
                        >
                            <div class="flex justify-between items-start gap-xxs">
                                <span class="fx-badge">{{ $w->type === 'rpv' ? 'RPV' : 'Precat.' }}</span>
                                <div class="flex items-center gap-xxs">
                                    <a href="{{ route('writs.show', $w) }}" class="text-xxs text-mono-600 hover:text-primary-500">abrir →</a>
                                    <button
                                        wire:click="delete({{ $w->id }})"
                                        wire:confirm="Excluir este requisitório e todas as transações vinculadas?"
                                        class="text-xxs text-system-error hover:opacity-70 leading-none"
                                        title="Excluir"
                                    >✕</button>
                                </div>
                            </div>
                            <div class="mt-xxxs text-sm font-medium truncate" title="{{ $w->process_number }}">
                                {{ $w->process_number ?: '#'.$w->id }}
                            </div>
                            <div class="text-xxs text-mono-600 truncate">{{ $w->debtor_entity }}</div>
                            <div class="mt-xxs text-xxs flex justify-between">
                                <span class="text-mono-600">Face</span>
                                <span class="font-semibold">R$ {{ number_format($w->face_value, 2, ',', '.') }}</span>
                            </div>
                            <div class="text-xxs flex justify-between">
                                <span class="text-mono-600">Pago</span>
                                <span>R$ {{ number_format($w->paid_amount, 2, ',', '.') }}</span>
                            </div>
                            @if ($stage['key'] === 'finalized' && $w->actual_receipt_amount)
                                <div class="text-xxs flex justify-between text-system-up font-semibold">
                                    <span>Recebido</span>
                                    <span>R$ {{ number_format($w->actual_receipt_amount, 2, ',', '.') }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

@assets
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
@endassets

@script
<script>
    Alpine.data('writsKanban', () => ({
        init() {
            const lists = this.$el.querySelectorAll('.kanban-list');
            lists.forEach(list => {
                new Sortable(list, {
                    group: 'writs',
                    animation: 150,
                    ghostClass: 'opacity-50',
                    onAdd: (evt) => {
                        const id = parseInt(evt.item.dataset.id);
                        const newStage = evt.to.dataset.stage;
                        $wire.moveCard(id, newStage);
                    },
                });
            });
        }
    }));
</script>
@endscript

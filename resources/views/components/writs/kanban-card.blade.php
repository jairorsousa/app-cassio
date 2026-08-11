@props([
    'writ',
    'stage',
    'meta',
])

@php
    $clientName = $writ->assignor_name ?: ($writ->assignors->first()?->contact?->name ?: 'Cedente não informado');
    $faceValue = (float) $writ->face_value;
    $negotiatedValue = (float) $writ->negotiated_amount;
    $totalCost = (float) $writ->totalCost();

    $dateItems = match ($stage) {
        'monitoring' => [[
            'label' => 'Monitoramento',
            'value' => $writ->monitoring_at?->format('d/m/Y H:i'),
            'icon' => 'manage_search',
        ]],
        'pending' => [[
            'label' => 'Cessão',
            'value' => $writ->cession_at?->format('d/m/Y H:i'),
            'icon' => 'edit_calendar',
        ]],
        'paid' => [[
            'label' => 'Pagamento',
            'value' => $writ->paid_at?->format('d/m/Y'),
            'icon' => 'calendar_today',
        ]],
        'petitioning' => [
            [
                'label' => 'Pagamento',
                'value' => $writ->paid_at?->format('d/m/Y'),
                'icon' => 'calendar_today',
            ],
            [
                'label' => 'Peticionamento',
                'value' => $writ->petitioned_at?->format('d/m/Y H:i'),
                'icon' => 'gavel',
            ],
        ],
        'awaiting_receipt' => [
            [
                'label' => 'Pagamento',
                'value' => $writ->paid_at?->format('d/m/Y'),
                'icon' => 'calendar_today',
            ],
            [
                'label' => 'Aguardando desde',
                'value' => $writ->awaiting_receipt_at?->format('d/m/Y H:i'),
                'icon' => 'hourglass_top',
            ],
        ],
        'finalized' => [
            [
                'label' => 'Pagamento',
                'value' => $writ->paid_at?->format('d/m/Y'),
                'icon' => 'calendar_today',
            ],
            [
                'label' => 'Recebimento',
                'value' => $writ->finalized_at?->format('d/m/Y'),
                'icon' => 'event_available',
            ],
        ],
        'lost' => [[
            'label' => 'Encerrado em',
            'value' => $writ->lost_at?->format('d/m/Y H:i'),
            'icon' => 'event_busy',
        ]],
        default => [],
    };

    $statusLabel = match ($stage) {
        'monitoring' => 'Monitorando',
        'negotiation' => 'Em negociação',
        'pending' => 'Cessão pendente',
        'paid' => 'Pago',
        'petitioning' => 'Em peticionamento',
        'awaiting_receipt' => 'Aguardando',
        'finalized' => 'Recebido',
        'lost' => 'Perdido',
        default => $writ->stageLabel(),
    };

    $statusAmount = match ($stage) {
        'monitoring' => $faceValue,
        'negotiation', 'pending' => (float) ($writ->proposed_amount ?: $writ->negotiated_amount),
        'paid', 'petitioning' => $totalCost,
        'awaiting_receipt' => (float) ($writ->estimated_receipt_amount ?: $totalCost),
        'finalized' => (float) ($writ->actual_receipt_amount ?? $writ->estimated_receipt_amount),
        'lost' => $negotiatedValue > 0 ? $negotiatedValue : $faceValue,
        default => 0,
    };
@endphp

<article
    class="kanban-card flex cursor-grab overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-card transition-all hover:-translate-y-0.5 hover:shadow-elevated"
    data-id="{{ $writ->id }}"
    wire:key="writ-{{ $writ->id }}"
>
    <div class="w-1.5 shrink-0 {{ $meta['card_accent'] }}" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-1 flex-col p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5 pt-1">
                <span class="h-3 w-3 shrink-0 rounded-full {{ $meta['dot'] }}" aria-hidden="true"></span>
                <a
                    href="{{ route('writs.show', $writ) }}"
                    class="truncate text-[15px] font-bold text-mono-900 transition-colors hover:text-primary-500"
                    title="{{ $clientName }}"
                >
                    {{ $clientName }}
                </a>
            </div>

            <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600"
                    @click="open = !open"
                    :aria-expanded="open"
                    aria-label="Ações de {{ $clientName }}"
                >
                    <span class="material-icons-outlined text-[20px]">more_vert</span>
                </button>

                <div x-show="open" x-transition class="absolute right-0 top-9 z-dropdown w-40 rounded-xl border border-mono-100 bg-mono-white py-2 shadow-dropdown" style="display: none;">
                    <a href="{{ route('writs.show', $writ) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-mono-900 hover:bg-mono-50">
                        <span class="material-icons-outlined text-[18px] text-mono-400">visibility</span>
                        Abrir
                    </a>
                    <a href="{{ route('writs.edit', $writ) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-mono-900 hover:bg-mono-50">
                        <span class="material-icons-outlined text-[18px] text-mono-400">edit</span>
                        Editar
                    </a>
                    @if (in_array($stage, ['monitoring', 'negotiation'], true))
                        <button
                            type="button"
                            wire:click="promptLostReason({{ $writ->id }})"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-down hover:bg-down-bg"
                        >
                            <span class="material-icons-outlined text-[18px]">block</span>
                            Marcar como perdido
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="delete({{ $writ->id }})"
                        wire:confirm="Excluir este requisitório e todas as transações vinculadas?"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-error hover:bg-down-bg"
                    >
                        <span class="material-icons-outlined text-[18px]">delete_outline</span>
                        Excluir
                    </button>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-2 text-sm text-mono-600">
            <span class="material-icons-outlined text-[18px]">tag</span>
            <span class="truncate" title="{{ $writ->process_number }}">{{ $writ->process_number ?: 'Requisitório #'.$writ->id }}</span>
        </div>

        @if ($stage === 'monitoring' && $faceValue <= 0 && $negotiatedValue <= 0)
            <div class="mt-4 rounded-xl bg-mono-50 px-3 py-4 text-center text-sm font-semibold text-mono-600">
                Valores ainda não cadastrados
            </div>
        @else
            <dl class="mt-5 grid grid-cols-2 divide-x divide-mono-100">
                <div class="min-w-0 pr-3">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-mono-600">Face</dt>
                    <dd class="mt-2 truncate text-lg font-semibold text-mono-900" title="R$ {{ number_format($faceValue, 2, ',', '.') }}">
                        R$ {{ number_format($faceValue, 2, ',', '.') }}
                    </dd>
                </div>
                <div class="min-w-0 pl-3">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] {{ $meta['metric_text'] }}">Parte negociada</dt>
                    <dd class="mt-2 truncate text-xl font-bold {{ $meta['metric_text'] }}" title="{{ $negotiatedValue > 0 ? 'R$ '.number_format($negotiatedValue, 2, ',', '.') : 'Não informada' }}">
                        {{ $negotiatedValue > 0 ? 'R$ '.number_format($negotiatedValue, 2, ',', '.') : '—' }}
                    </dd>
                </div>
            </dl>
        @endif

        <div class="mt-5 flex items-center justify-between gap-4 border-y border-mono-100 py-3">
            <span @class([
                'shrink-0 rounded-pill px-3 py-1.5 text-xs font-bold',
                'bg-primary-100 text-primary-500' => $writ->type === 'rpv',
                'bg-info-bg text-info' => $writ->type !== 'rpv',
            ])>
                {{ $writ->type === 'rpv' ? 'RPV' : 'Precatório' }}
            </span>

            <div class="min-w-0 text-right">
                <p class="text-[11px] font-medium text-mono-600">Investimento</p>
                <p class="truncate text-sm font-bold text-mono-900" title="{{ $totalCost > 0 ? 'R$ '.number_format($totalCost, 2, ',', '.') : 'Não informado' }}">
                    {{ $totalCost > 0 ? 'R$ '.number_format($totalCost, 2, ',', '.') : '—' }}
                </p>
            </div>
        </div>

        @if ($dateItems !== [])
            <dl @class([
                'mt-4 grid gap-3',
                'grid-cols-1' => count($dateItems) === 1,
                'grid-cols-2' => count($dateItems) > 1,
            ])>
                @foreach ($dateItems as $dateItem)
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $dateItem['value'] ? $meta['date_icon'] : 'bg-down-bg text-down' }}">
                            <span class="material-icons-outlined text-[21px]">{{ $dateItem['icon'] }}</span>
                        </div>
                        <div class="min-w-0">
                            <dt class="truncate text-[10px] font-medium text-mono-600" title="{{ $dateItem['label'] }}">{{ $dateItem['label'] }}</dt>
                            <dd @class([
                                'mt-0.5 truncate text-xs font-bold',
                                'text-mono-900' => $dateItem['value'],
                                'text-down' => ! $dateItem['value'],
                            ]) title="{{ $dateItem['value'] ?: 'Não informado' }}">
                                {{ $dateItem['value'] ?: 'Sem data' }}
                            </dd>
                        </div>
                    </div>
                @endforeach
            </dl>
        @endif

        <div class="mt-4 flex items-center justify-between gap-3 rounded-xl px-3.5 py-3 {{ $meta['status'] }}">
            <span class="min-w-0 truncate text-sm font-bold">{{ $statusLabel }}</span>
            <span class="shrink-0 text-sm font-bold">
                {{ $statusAmount > 0 ? 'R$ '.number_format($statusAmount, 2, ',', '.') : '—' }}
            </span>
        </div>

        @if ($stage === 'lost' && $writ->lost_reason)
            <div class="mt-3 rounded-xl bg-down-bg px-3 py-2.5 text-xs text-down">
                <div class="mb-1 flex items-center gap-1 font-semibold">
                    <span class="material-icons-outlined text-[15px]">block</span>
                    Motivo da perda
                </div>
                <p class="line-clamp-3">{{ $writ->lost_reason }}</p>
            </div>
        @endif
    </div>
</article>

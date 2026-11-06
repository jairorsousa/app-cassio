<?php

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Support\TransmissionDispatcher;
use Livewire\Volt\Component;

new class extends Component {
    public const BATCHES_PER_PAGE = 10;
    public const RECIPIENTS_PER_PAGE = 20;

    public int $batchPage = 1;
    public ?int $openBatchId = null;
    public int $recipientPage = 1;
    public string $recipientSearch = '';
    public string $recipientStatus = '';
    public ?string $notice = null;
    public ?string $errorNotice = null;

    public function batchPageTo(int $page): void
    {
        $this->batchPage = max(1, $page);
    }

    public function openBatch(int $id): void
    {
        TransmissionDispatcher::owned($id, auth()->id());
        $this->openBatchId = $this->openBatchId === $id ? null : $id;
        $this->reset('recipientPage', 'recipientSearch', 'recipientStatus');
    }

    public function updatedRecipientSearch(): void { $this->recipientPage = 1; }
    public function updatedRecipientStatus(): void
    {
        if ($this->recipientStatus !== '' && ! array_key_exists($this->recipientStatus, TransmissionRecipient::STATUSES)) {
            $this->recipientStatus = '';
        }
        $this->recipientPage = 1;
    }
    public function recipientPageTo(int $page): void { $this->recipientPage = max(1, $page); }

    private function flash(bool $ok, string $yes, string $no): void
    {
        $this->notice = $ok ? $yes : null;
        $this->errorNotice = $ok ? null : $no;
    }

    public function pause(int $id): void
    {
        $this->flash(TransmissionDispatcher::pause($id, auth()->id()), 'Transmissão pausada. Quem já estava sendo enviado neste momento conclui; os próximos ficam aguardando.', 'Esta transmissão não pode ser pausada agora.');
    }

    public function resume(int $id): void
    {
        $this->flash(TransmissionDispatcher::resume($id, auth()->id()), 'Transmissão retomada. Os pendentes foram reagendados com 45 a 90 s entre cada envio.', 'Esta transmissão não está pausada.');
    }

    public function cancel(int $id): void
    {
        $this->flash(TransmissionDispatcher::cancel($id, auth()->id()), 'Transmissão cancelada. Os pendentes não serão enviados.', 'Esta transmissão já terminou.');
    }

    public function retryFailed(int $id): void
    {
        TransmissionDispatcher::owned($id, auth()->id());
        try {
            $new = TransmissionDispatcher::retryFailed($id, auth()->id());
            $this->flash(true, 'Reenvio criado (transmissão #'.$new->id.') somente para quem falhou.', '');
            $this->openBatchId = $new->id;
            $this->batchPage = 1;
        } catch (\RuntimeException $e) {
            $this->flash(false, '', $e->getMessage());
        }
    }

    public function pauseSchedule(int $id): void
    {
        $this->flash(\App\Support\TransmissionScheduler::pause($id, auth()->id()), 'Agendamento semanal pausado. Nenhum novo envio será gerado.', 'Este agendamento não pode ser pausado.');
    }

    public function resumeSchedule(int $id): void
    {
        $this->flash(\App\Support\TransmissionScheduler::resume($id, auth()->id()), 'Agendamento retomado a partir da próxima data futura (sem envios atrasados).', 'Este agendamento não está pausado.');
    }

    public function cancelSchedule(int $id): void
    {
        $this->flash(\App\Support\TransmissionScheduler::cancel($id, auth()->id()), 'Agendamento cancelado. Nenhum envio futuro será gerado.', 'Este agendamento já terminou.');
    }

    public function with(): array
    {
        $uid = auth()->id();
        $schedules = \App\Domains\Broadcasts\Models\TransmissionSchedule::where('created_by', $uid)->whereIn('status', ['active', 'paused', 'failed'])->orderBy('next_run_at')->limit(20)->get();
        $base = TransmissionBatch::where('created_by', $uid);
        $total = (clone $base)->count();
        $pages = max(1, (int) ceil($total / self::BATCHES_PER_PAGE));
        $this->batchPage = min($this->batchPage, $pages);
        $batches = (clone $base)->with('author:id,name')->withCount('retries')->orderByDesc('id')
            ->forPage($this->batchPage, self::BATCHES_PER_PAGE)->get();
        $raw = TransmissionRecipient::whereIn('batch_id', $batches->pluck('id'))
            ->selectRaw('batch_id, status, count(*) n')->groupBy('batch_id', 'status')->get();
        $counts = [];
        foreach ($raw as $row) {
            $counts[$row->batch_id][$row->status] = (int) $row->n;
        }

        $open = $this->openBatchId ? (clone $base)->find($this->openBatchId) : null;
        $recipients = collect();
        $recipientTotal = 0;
        $retry = null;
        if ($open) {
            $q = $open->recipients()->when($this->recipientStatus !== '', fn ($q) => $q->where('status', $this->recipientStatus))
                ->when(trim($this->recipientSearch) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->recipientSearch).'%'));
            $recipientTotal = (clone $q)->count();
            $recipients = $q->orderBy('position')->forPage($this->recipientPage, self::RECIPIENTS_PER_PAGE)->get();
            $retry = TransmissionDispatcher::retryInfo($open);
        }
        $live = $batches->contains(fn ($b) => in_array($b->status, ['queued', 'running'], true));

        return compact('schedules', 'batches', 'counts', 'total', 'pages', 'open', 'recipients', 'recipientTotal', 'retry', 'live')
            + ['recipientPages' => max(1, (int) ceil($recipientTotal / self::RECIPIENTS_PER_PAGE))];
    }
}; ?>

<div class="flex flex-col gap-md" data-history @if ($live) wire:poll.20s @endif>
    <x-fx.alert variant="info">Aqui começa o histórico das transmissões confirmadas a partir desta atualização. Envios antigos não aparecem. "Aceita pelo Chatwoot" não confirma entrega nem leitura.</x-fx.alert>
    @if ($notice)<x-fx.alert variant="success">{{ $notice }}</x-fx.alert>@endif
    @if ($errorNotice)<x-fx.alert variant="warning">{{ $errorNotice }}</x-fx.alert>@endif

    @foreach ($schedules as $s)
        <x-fx.card wire:key="schedule-{{ $s->id }}" data-schedule="{{ $s->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-semibold text-mono-900">Agendamento #{{ $s->id }} · {{ \App\Domains\Broadcasts\Models\TransmissionSchedule::KIND_LABELS[$s->kind] ?? $s->kind }}</p>
                    <p class="text-xs text-mono-600">{{ \App\Domains\Broadcasts\Models\TransmissionSchedule::STATUS_LABELS[$s->status] ?? $s->status }}@if ($s->next_run_at && $s->status === 'active') · Próximo envio: {{ $s->next_run_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} (Fortaleza)@endif · Etiquetas: {{ implode(', ', (array) $s->labels) }}@if ($s->media_name) · Anexo: {{ $s->media_name }}@endif</p>
                    @if ($s->last_error)<p class="text-xs text-error">Último problema: {{ $s->last_error }}</p>@endif
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    @if ($s->kind === 'weekly' && $s->status === 'active')<button type="button" wire:click="pauseSchedule({{ $s->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Pausar</button>@endif
                    @if ($s->kind === 'weekly' && $s->status === 'paused')<button type="button" wire:click="resumeSchedule({{ $s->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Retomar</button>@endif
                    @if (in_array($s->status, ['active', 'paused'], true))<button type="button" wire:click="cancelSchedule({{ $s->id }})" wire:confirm="Cancelar este agendamento? Nenhum envio futuro será gerado." class="h-9 rounded-xl border border-error px-3 text-error">Cancelar agendamento</button>@endif
                </div>
            </div>
            <p class="mt-2 text-sm text-mono-800">{{ \Illuminate\Support\Str::limit($s->body, 160) }}</p>
        </x-fx.card>
    @endforeach

    @forelse ($batches as $b)
        @php
            $c = $counts[$b->id] ?? [];
            $done = ($c['sent'] ?? 0) + ($c['failed'] ?? 0) + ($c['unconfirmed'] ?? 0) + ($c['cancelled'] ?? 0) + ($c['suppressed'] ?? 0);
        @endphp
        <x-fx.card wire:key="batch-{{ $b->id }}" data-batch="{{ $b->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-semibold text-mono-900">Transmissão #{{ $b->id }} @if ($b->retry_of_id)<span class="text-xs font-normal text-mono-600">· Reenvio das falhas da #{{ $b->retry_of_id }}</span>@endif</p>
                    <p class="text-xs text-mono-600">{{ $b->created_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} (Fortaleza) · {{ $b->author?->name ?? '—' }} · {{ \App\Domains\Broadcasts\Models\TransmissionBatch::STATUS_LABELS[$b->status] ?? $b->status }}</p>
                    <p class="text-xs text-mono-600">Etiquetas: {{ implode(', ', (array) $b->labels) ?: '—' }} · {{ $b->recipients_count }} pessoa(s)@if ($b->mediaKind()) · {{ $b->mediaKind() }}: {{ $b->media_name }}@endif</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    @if (in_array($b->status, ['queued', 'running'], true))
                        <button type="button" wire:click="pause({{ $b->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Pausar</button>
                    @endif
                    @if ($b->status === 'paused')
                        <button type="button" wire:click="resume({{ $b->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Retomar</button>
                    @endif
                    @if (in_array($b->status, ['queued', 'running', 'paused'], true))
                        <button type="button" wire:click="cancel({{ $b->id }})" wire:confirm="Cancelar transmissão? Mensagens já aceitas não podem ser retiradas." class="h-9 rounded-xl border border-error px-3 text-error">Cancelar</button>
                    @endif
                    <button type="button" wire:click="openBatch({{ $b->id }})" aria-expanded="{{ $open?->id === $b->id ? 'true' : 'false' }}" class="h-9 rounded-xl border border-mono-200 px-3">{{ $open?->id === $b->id ? 'Fechar' : 'Detalhes' }}</button>
                </div>
            </div>
            <p class="mt-2 text-sm text-mono-800">{{ \Illuminate\Support\Str::limit($b->body, 160) }}</p>
            <p class="mt-2 text-xs text-mono-700">{{ $done }}/{{ $b->recipients_count }} processado(s) ·
                @foreach (\App\Domains\Broadcasts\Models\TransmissionRecipient::STATUSES as $k => $l)@if ($c[$k] ?? 0){{ $l }}: {{ $c[$k] }} · @endif @endforeach
            </p>
            @if ($b->status === 'paused')<p class="mt-1 text-xs text-mono-600">Pausada: nenhum novo envio começa até você retomar.</p>@endif

            @if ($open?->id === $b->id)
                <div class="mt-4 border-t border-mono-100 pt-4" data-batch-details>
                    <p class="whitespace-pre-line text-sm text-mono-800">{{ $open->body }}</p>
                    @if ($open->media_removed_at)<p class="mt-1 text-xs text-mono-600">Anexo removido do servidor em {{ $open->media_removed_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}.</p>@endif

                    @if ($retry && $retry['failed'] > 0)
                        <div class="mt-3 rounded-xl bg-mono-50 p-3 text-sm">
                            @if ($retry['blocked'])
                                <p class="text-mono-700" data-retry-blocked>{{ $retry['blocked'] }}</p>
                            @else
                                <button type="button" wire:click="retryFailed({{ $open->id }})" wire:confirm="Reenviar somente para {{ $retry['failed'] }} pessoa(s) com falha? As {{ $retry['unconfirmed'] }} sem confirmação NÃO serão reenviadas (podem ter recebido)." class="h-9 rounded-xl bg-primary-500 px-3 text-white">Reenviar somente falhas ({{ $retry['failed'] }})</button>
                                <p class="mt-1 text-xs text-mono-600">"Sem confirmação" não entra no reenvio, para evitar mensagem duplicada.</p>
                            @endif
                        </div>
                    @endif

                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        <x-jr.input label="Buscar pelo nome" name="recipientSearch" wire:model.live.debounce.400ms="recipientSearch" />
                        <div>
                            <label for="recipient-status" class="mb-2 block text-sm font-medium text-mono-600">Situação</label>
                            <select id="recipient-status" wire:model.live="recipientStatus" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900">
                                <option value="">Todas</option>
                                @foreach (\App\Domains\Broadcasts\Models\TransmissionRecipient::STATUSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <ul class="mt-3 divide-y divide-mono-100 text-sm">
                        @forelse ($recipients as $r)
                            <li class="flex flex-wrap justify-between gap-2 py-2" wire:key="rec-{{ $r->id }}">
                                <span>{{ $r->name ?: 'Sem nome' }} <span class="text-xs text-mono-500">{{ $r->maskedPhone() }}</span></span>
                                <span class="text-xs text-mono-700">{{ \App\Domains\Broadcasts\Models\TransmissionRecipient::STATUSES[$r->status] ?? $r->status }}@if ($r->finished_at) · {{ $r->finished_at->timezone('America/Fortaleza')->format('d/m H:i') }}@endif @if ($r->error && in_array($r->status, ['failed', 'unconfirmed'], true))· {{ \Illuminate\Support\Str::limit($r->error, 80) }}@endif</span>
                            </li>
                        @empty
                            <li class="py-2 text-mono-500">Nenhum destinatário com esse filtro.</li>
                        @endforelse
                    </ul>
                    @if ($recipientPages > 1)
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <button type="button" wire:click="recipientPageTo({{ $recipientPage - 1 }})" @disabled($recipientPage <= 1) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Anterior</button>
                            <span>Página {{ $recipientPage }} de {{ $recipientPages }} · {{ $recipientTotal }} destinatário(s)</span>
                            <button type="button" wire:click="recipientPageTo({{ $recipientPage + 1 }})" @disabled($recipientPage >= $recipientPages) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Próxima</button>
                        </div>
                    @endif
                </div>
            @endif
        </x-fx.card>
    @empty
        <p class="text-sm text-mono-500">Nenhuma transmissão registrada ainda.</p>
    @endforelse

    @if ($pages > 1)
        <div class="flex items-center justify-between text-xs">
            <button type="button" wire:click="batchPageTo({{ $batchPage - 1 }})" @disabled($batchPage <= 1) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Anterior</button>
            <span>Página {{ $batchPage }} de {{ $pages }} · {{ $total }} transmissão(ões)</span>
            <button type="button" wire:click="batchPageTo({{ $batchPage + 1 }})" @disabled($batchPage >= $pages) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Próxima</button>
        </div>
    @endif
</div>

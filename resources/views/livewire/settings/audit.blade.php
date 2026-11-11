<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $userId = '';
    #[Url] public string $entity = '';
    #[Url] public string $action = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updating($name): void
    {
        if (in_array($name, ['from', 'to', 'userId', 'entity', 'action'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('from', 'to', 'userId', 'entity', 'action');
        $this->resetPage();
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $q = AuditLog::query()->orderByDesc('id');
        if ($this->from) { $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($this->from, 'America/Fortaleza')->startOfDay()->utc()); }
        if ($this->to) { $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($this->to, 'America/Fortaleza')->endOfDay()->utc()); }
        if ($this->userId !== '') { $q->where('user_id', (int) $this->userId); }
        if ($this->entity !== '') { $q->where('entity_type', $this->entity); }
        if ($this->action !== '') { $q->where('action', $this->action); }

        return [
            'logs' => $q->paginate(50),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'entities' => AuditLog::query()->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
        ];
    }
}; ?>

<x-slot name="header">Log de auditoria</x-slot>

<div class="flex flex-col gap-4" data-audit-page>
    <div>
        <x-back-button :href="route('settings')" />
        <h2 class="mt-4 text-2xl font-bold tracking-tight">Log de auditoria</h2>
        <p class="text-sm text-mono-600">Quem criou, editou ou excluiu cada registro, e os acessos (entrar/sair). Horários em Fortaleza (UTC−3). Somente administrador.</p>
    </div>

    <div class="grid grid-cols-2 gap-2 md:grid-cols-6">
        <label class="text-xs text-mono-600">De<input type="date" wire:model.live="from" class="mt-1 h-10 w-full rounded-xl border border-mono-200 px-2 text-sm"></label>
        <label class="text-xs text-mono-600">Até<input type="date" wire:model.live="to" class="mt-1 h-10 w-full rounded-xl border border-mono-200 px-2 text-sm"></label>
        <label class="text-xs text-mono-600">Usuário
            <select wire:model.live="userId" class="mt-1 h-10 w-full rounded-xl border border-mono-200 px-2 text-sm"><option value="">Todos</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
        </label>
        <label class="text-xs text-mono-600">Registro
            <select wire:model.live="entity" class="mt-1 h-10 w-full rounded-xl border border-mono-200 px-2 text-sm"><option value="">Todos</option>@foreach ($entities as $e)<option value="{{ $e }}">{{ $e }}</option>@endforeach</select>
        </label>
        <label class="text-xs text-mono-600">Ação
            <select wire:model.live="action" class="mt-1 h-10 w-full rounded-xl border border-mono-200 px-2 text-sm"><option value="">Todas</option>@foreach (AuditLog::ACTIONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </label>
        <div class="flex items-end"><button type="button" wire:click="clearFilters" class="h-10 w-full rounded-xl border border-mono-200 px-3 text-sm">Limpar filtros</button></div>
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($logs as $log)
            <div class="rounded-xl border border-mono-100 bg-mono-white p-3 text-sm" wire:key="audit-{{ $log->id }}" x-data="{ open: false }">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold">{{ $log->created_at->timezone('America/Fortaleza')->format('d/m/Y H:i:s') }}</span>
                    <span>{{ $log->user_name ?? 'Sem usuário' }}</span>
                    <span class="rounded-pill bg-mono-100 px-2 py-0.5 text-xs font-semibold">{{ $log->actionLabel() }}</span>
                    <span class="text-mono-600">{{ $log->entity_type }}@if ($log->entity_id) #{{ $log->entity_id }}@endif @if ($log->entity_label) · {{ $log->entity_label }}@endif</span>
                    @if ($log->before || $log->after)<button type="button" class="ml-auto text-xs text-primary-600" x-on:click="open = !open" x-text="open ? 'Ocultar detalhes' : 'Ver antes/depois'"></button>@endif
                </div>
                @if ($log->before || $log->after)
                    <div x-show="open" style="display:none" class="mt-2 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="text-left text-mono-600"><th class="pr-3">Campo</th><th class="pr-3">Antes</th><th>Depois</th></tr></thead>
                            <tbody>
                                @foreach (array_unique(array_merge(array_keys($log->before ?? []), array_keys($log->after ?? []))) as $field)
                                    <tr class="border-t border-mono-100 align-top">
                                        <td class="pr-3 font-medium">{{ $field }}</td>
                                        <td class="pr-3 text-down break-all">{{ is_scalar($log->before[$field] ?? null) ? ($log->before[$field] ?? '—') : json_encode($log->before[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</td>
                                        <td class="text-up break-all">{{ is_scalar($log->after[$field] ?? null) ? ($log->after[$field] ?? '—') : json_encode($log->after[$field] ?? null, JSON_UNESCAPED_UNICODE) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-mono-100 bg-mono-white p-4 text-sm text-mono-600">Nenhum registro no período.</div>
        @endforelse
    </div>
    {{ $logs->links() }}
</div>

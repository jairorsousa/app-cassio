<?php

use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Services\DivisionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showGroupModal = false;

    public string $groupName = '';

    public string $groupType = 'amigos';

    public string $groupNotes = '';

    public bool $groupRequiresApproval = true;

    public string $rejectReason = '';

    public ?string $notice = null;

    public function mount(DivisionService $service): void
    {
        $service->claimMemberships(auth()->user());
    }

    public function openGroup(): void
    {
        $this->reset('groupName', 'groupNotes');
        $this->groupType = 'amigos';
        $this->groupRequiresApproval = true;
        $this->resetValidation();
        $this->showGroupModal = true;
    }

    public function cancel(): void
    {
        $this->showGroupModal = false;
    }

    public function saveGroup(DivisionService $service): void
    {
        try {
            $group = $service->createGroup(auth()->user(), ['name' => $this->groupName, 'type' => $this->groupType, 'notes' => $this->groupNotes, 'requires_approval' => $this->groupRequiresApproval]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->showGroupModal = false;
        $this->notice = $group->status === 'pending' ? 'Pedido enviado. O grupo fica aguardando a aprovação do Cassio.' : 'Grupo criado.';
    }

    public function decideGroup(int $id, bool $approve, DivisionService $service): void
    {
        $service->decideGroup(auth()->user(), DivisionGroup::findOrFail($id), $approve, $this->rejectReason ?: null);
        $this->rejectReason = '';
        $this->notice = $approve ? 'Grupo aprovado.' : 'Grupo recusado.';
    }

    public ?string $approvedLink = null;

    public function decideMember(int $id, bool $approve, DivisionService $service): void
    {
        $link = $service->decideMember(auth()->user(), \App\Domains\Divisions\Models\DivisionMember::findOrFail($id), $approve);
        $this->approvedLink = $link;
        $this->notice = $approve ? 'Participante aprovado.'.($link ? ' Link de convite gerado abaixo.' : '') : 'Pedido recusado: a pessoa foi removida do grupo.';
    }

    public function decideExpense(int $id, bool $approve, DivisionService $service): void
    {
        $service->decideExpense(auth()->user(), DivisionExpense::findOrFail($id), $approve);
        $this->notice = $approve ? 'Despesa aprovada.' : 'Despesa recusada.';
    }

    public ?string $chargeKey = null;

    public string $chargeText = '';

    private function chargeTarget(): ?array
    {
        if (! auth()->user()->isAdmin() || ! $this->chargeKey) {
            return null;
        }
        foreach (app(DivisionService::class)->overall(auth()->user()) as $p) {
            if ($p['key'] === $this->chargeKey && $p['cents'] > 0 && $p['phone']) {
                return $p;
            }
        }

        return null;
    }

    public function openCharge(string $key): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->chargeKey = $key;
        $p = $this->chargeTarget();
        if (! $p) {
            $this->chargeKey = null;

            return;
        }
        $groups = collect($p['groups'])->filter(fn ($g) => $g['cents'] > 0);
        $link = $groups->count() === 1 ? route('divisions.show', $groups->keys()->first()) : route('divisions.index');
        $this->chargeText = app(DivisionService::class)->chargeMessage(auth()->user(), $p['name'], $p['cents'], $groups->pluck('name')->values()->all(), $link);
    }

    public function logCharge(): void
    {
        $p = $this->chargeTarget();
        if ($p) {
            foreach ($p['groups'] as $g) {
                if ($g['cents'] > 0) {
                    app(DivisionService::class)->logCharge(auth()->user(), \App\Domains\Divisions\Models\DivisionMember::findOrFail($g['member_id']), $g['cents']);
                }
            }
        }
        $this->reset('chargeKey', 'chargeText');
        $this->notice = 'Cobrança aberta no WhatsApp e registrada no histórico.';
    }

    public function with(): array
    {
        $service = app(DivisionService::class);
        $user = auth()->user();
        $groups = DivisionGroup::visibleTo($user)->with('members')->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 else 2 end")->orderBy('name')->get();

        return [
            'isAdmin' => $user->isAdmin(),
            'groups' => $groups->map(function ($g) use ($service, $user) {
                $me = $user->isAdmin() ? $g->ownerMember() : $g->memberFor($user);
                $net = $g->status === 'approved' ? $service->netBalances($g) : [];

                return ['group' => $g, 'mine' => $me ? ($net[$me->id] ?? 0) : 0];
            }),
            'overall' => $service->overall($user),
            'chargeTarget' => $this->chargeTarget(),
            'pendingGroups' => $user->isAdmin() ? DivisionGroup::where('status', 'pending')->with('members')->get() : collect(),
            'pendingMembers' => $user->isAdmin() ? \App\Domains\Divisions\Models\DivisionMember::where('approval_status', 'pending')->with('group', 'requester')->orderBy('id')->get() : collect(),
            'pendingExpenses' => $user->isAdmin() ? DivisionExpense::where('status', 'pending')->with('group', 'payer', 'creator')->orderBy('date')->get() : collect(),
            'service' => $service,
        ];
    }
}; ?>

<x-slot name="header">Divisões</x-slot>

<div class="flex flex-col gap-md" data-divisions-index>
    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif

    <x-fx.card>
        <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-fs-16 font-semibold text-cryptex-text-primary">Divisão de despesas</h2>
                <p class="mt-1 text-sm text-mono-600">Grupos com sócio, família ou amigos: quem pagou, quanto cada um deve e os acertos.</p>
            </div>
            <button type="button" class="fx-btn fx-btn--primary self-start sm:self-auto" wire:click="openGroup">
                <span class="material-icons-outlined text-[18px]">add</span>
                {{ $isAdmin ? 'Novo grupo' : 'Solicitar grupo' }}
            </button>
        </div>
    </x-fx.card>

    @if ($approvedLink)
        <div class="rounded-xl border border-primary-100 bg-primary-100 p-3 text-sm" x-data data-approved-link>
            <p class="mb-1 font-semibold">Link de convite (só Divisões):</p>
            <div class="flex gap-2"><input type="text" readonly value="{{ $approvedLink }}" class="h-10 flex-1 rounded-xl border-mono-200 text-xs" x-ref="l">
                <button type="button" class="fx-btn fx-btn--standard" x-on:click="navigator.clipboard.writeText($refs.l.value)">Copiar</button></div>
        </div>
    @endif
    @if ($isAdmin && ($pendingGroups->isNotEmpty() || $pendingExpenses->isNotEmpty() || $pendingMembers->isNotEmpty()))
        <x-fx.card data-approvals>
            <h3 class="mb-3 text-fs-16 font-semibold">Aguardando sua aprovação</h3>
            @foreach ($pendingGroups as $g)
                <div class="flex flex-col gap-2 border-b border-mono-100 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="pg-{{ $g->id }}">
                    <div class="text-sm"><strong>Grupo: {{ $g->name }}</strong> · {{ $g->typeLabel() }}<div class="text-mono-600">Pedido de {{ $g->members->where('is_owner', false)->pluck('name')->join(', ') }}@if ($g->notes) · {{ $g->notes }}@endif</div></div>
                    <div class="flex gap-2">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="decideGroup({{ $g->id }}, false)" wire:confirm="Recusar este grupo?">Recusar</button>
                        <button type="button" class="fx-btn fx-btn--primary" wire:click="decideGroup({{ $g->id }}, true)">Aprovar</button>
                    </div>
                </div>
            @endforeach
            @foreach ($pendingMembers as $m)
                <div class="flex flex-col gap-2 border-b border-mono-100 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="pm-{{ $m->id }}" data-pending-member>
                    <div class="text-sm"><strong>Participante externo: {{ $m->name }}</strong> · {{ $m->email }}@if ($m->phone) · {{ $m->phoneDisplay() }}@endif
                        <div class="text-mono-600">Grupo {{ $m->group?->name }} · pedido por {{ $m->requester?->name }}</div></div>
                    <div class="flex gap-2">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="decideMember({{ $m->id }}, false)" wire:confirm="Recusar e remover {{ $m->name }}?">Recusar</button>
                        <button type="button" class="fx-btn fx-btn--primary" wire:click="decideMember({{ $m->id }}, true)">Aprovar</button>
                    </div>
                </div>
            @endforeach
            @foreach ($pendingExpenses as $e)
                <div class="flex flex-col gap-2 border-b border-mono-100 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="pe-{{ $e->id }}">
                    <div class="text-sm"><strong>{{ $e->description }}</strong> · R$ {{ number_format((float) $e->amount, 2, ',', '.') }} · {{ $e->date->format('d/m/Y') }}
                        <div class="text-mono-600">{{ $e->group->name }} · pago por {{ $e->payer?->name }} · lançado por {{ $e->creator?->name }}</div></div>
                    <div class="flex gap-2">
                        <a href="{{ route('divisions.show', $e->group_id) }}" class="fx-btn fx-btn--standard">Ver</a>
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="decideExpense({{ $e->id }}, false)">Recusar</button>
                        <button type="button" class="fx-btn fx-btn--primary" wire:click="decideExpense({{ $e->id }}, true)">Aprovar</button>
                    </div>
                </div>
            @endforeach
        </x-fx.card>
    @endif

    <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-fx.card>
                <h3 class="mb-3 text-fs-16 font-semibold">Grupos</h3>
                @forelse ($groups as $row)
                    @php($g = $row['group'])
                    <a href="{{ $g->status === 'approved' ? route('divisions.show', $g) : '#' }}" class="flex items-center justify-between gap-3 border-b border-mono-100 py-3 last:border-0 hover:bg-mono-50" wire:key="g-{{ $g->id }}" data-group-row>
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-mono-900">{{ $g->name }}</div>
                            <div class="text-xs text-mono-600">{{ $g->typeLabel() }} · {{ $g->members->count() }} participante(s)
                                @if ($g->status !== 'approved')<span class="ml-1 rounded-pill bg-mono-100 px-2 py-0.5">{{ $g->statusLabel() }}</span>@endif</div>
                        </div>
                        @if ($g->status === 'approved')
                            <div class="text-right text-sm font-semibold {{ $row['mine'] > 0 ? 'text-system-up' : ($row['mine'] < 0 ? 'text-system-down' : 'text-mono-600') }}">
                                @if ($row['mine'] > 0) a receber R$ {{ $service->money($row['mine']) }} @elseif ($row['mine'] < 0) a pagar R$ {{ $service->money(-$row['mine']) }} @else quitado @endif
                            </div>
                        @endif
                    </a>
                @empty
                    <x-jr.empty-state title="Nenhum grupo ainda" description="{{ $isAdmin ? 'Crie o primeiro grupo para dividir despesas.' : 'Peça um grupo novo ou aguarde um convite.' }}" />
                @endforelse
            </x-fx.card>
        </div>
        <x-fx.card data-overall>
            <h3 class="mb-3 text-fs-16 font-semibold">Saldo geral por pessoa</h3>
            @forelse ($overall as $p)
                <div class="flex justify-between border-b border-mono-100 py-2 text-sm last:border-0">
                    <span>{{ $p['name'] }}</span>
                    <span class="font-semibold {{ $p['cents'] > 0 ? 'text-system-up' : 'text-system-down' }}">
                        {{ $p['cents'] > 0 ? 'te deve' : 'você deve' }} R$ {{ $service->money(abs($p['cents'])) }}
                        @if ($isAdmin && $p['cents'] > 0)
                            @if ($p['phone'])
                                <button type="button" class="ml-2 text-xs font-normal text-system-up" wire:click="openCharge(@js($p['key']))" data-charge>Cobrar</button>
                            @else
                                <button type="button" class="ml-2 cursor-not-allowed text-xs font-normal text-mono-400" disabled title="Cadastre o celular" data-charge-disabled>Cobrar</button>
                            @endif
                        @endif</span>
                </div>
            @empty
                <p class="text-sm text-mono-600">Tudo acertado.</p>
            @endforelse
        </x-fx.card>
    </div>

    @if ($chargeTarget)
        @include('divisions.charge-modal', ['phone' => $chargeTarget['phone'], 'name' => $chargeTarget['name'], 'cents' => $chargeTarget['cents'], 'service' => $service])
    @endif

    @if ($showGroupModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>
            <div class="relative flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center border-b border-mono-100 px-6"><h3 class="text-lg font-bold text-mono-900">{{ $isAdmin ? 'Novo grupo' : 'Solicitar grupo' }}</h3></div>
                <form wire:submit="saveGroup" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <x-jr.input label="Nome *" icon="groups" name="groupName" wire:model="groupName" placeholder="Ex.: Viagem Barreirinhas" />
                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                            <select wire:model="groupType" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm">
                                @foreach (\App\Domains\Divisions\Models\DivisionGroup::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                            @error('groupType')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror
                        </div>
                        @if ($isAdmin)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="groupRequiresApproval"> Despesas lançadas pelos participantes precisam da minha aprovação</label>
                        @else
                            <p class="text-xs text-mono-600">O grupo fica "Aguardando aprovação" até o Cassio aprovar.</p>
                        @endif
                        <x-jr.input label="Observação" name="groupNotes" wire:model="groupNotes" />
                    </div>
                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

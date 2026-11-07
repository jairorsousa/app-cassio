<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Models\DivisionSettlement;
use App\Domains\Divisions\Services\DivisionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public DivisionGroup $group;

    public ?string $notice = null;

    // Despesa
    public bool $showExpenseModal = false;

    public ?int $editingExpenseId = null;

    public string $expenseDescription = '';

    public string $expenseAmount = '';

    public string $expenseDate = '';

    public string $expenseCategory = 'outros';

    public string $paidBy = '';

    public string $splitMode = 'equal';

    /** @var array<int, array{on: bool, value: string}> */
    public array $splitRows = [];

    public ?int $blockedMemberId = null;

    public string $blockedReason = '';

    public string $pdfFrom = '';

    public string $pdfTo = '';

    public int $installments = 1;

    public string $transactionId = '';

    public string $expenseNotes = '';

    public $receipt = null;

    // Acerto
    public bool $showSettleModal = false;

    public string $settleFrom = '';

    public string $settleTo = '';

    public string $settleAmount = '';

    public string $settleDate = '';

    public string $settleAccount = '';

    public string $settleNotes = '';

    // Participantes / configurações
    public string $memberName = '';

    public string $memberEmail = '';

    public string $memberPhone = '';

    public ?int $phoneEditId = null;

    public string $phoneDraft = '';

    public ?int $chargeMemberId = null;

    public int $chargeCents = 0;

    public string $chargeText = '';

    public ?string $inviteLink = null;

    public bool $showSettings = false;

    public string $editName = '';

    public string $editType = '';

    public bool $editRequiresApproval = true;

    public function mount(DivisionGroup $group, DivisionService $service): void
    {
        $service->claimMemberships(auth()->user());
        $service->assertView(auth()->user(), $group);
        abort_unless($group->status === 'approved', 404);
        $this->group = $group;
    }

    private function service(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function fail(ValidationException $e): void
    {
        $this->setErrorBag($e->validator->errors());
    }

    private function me(): ?DivisionMember
    {
        $this->group->load('members');

        return auth()->user()->isAdmin() ? $this->group->ownerMember() : $this->group->memberFor(auth()->user());
    }

    // ---------- Despesa ----------

    private function resetExpense(): void
    {
        $this->reset('editingExpenseId', 'expenseDescription', 'expenseAmount', 'expenseNotes', 'receipt', 'transactionId');
        $this->expenseDate = now()->toDateString();
        $this->expenseCategory = 'outros';
        $this->splitMode = 'equal';
        $this->installments = 1;
        $this->paidBy = (string) ($this->me()?->id ?? '');
        $this->splitRows = $this->group->members->filter(fn ($m) => $m->active)->mapWithKeys(fn ($m) => [$m->id => ['on' => true, 'value' => '']])->all();
        $this->resetValidation();
    }

    public function newExpense(): void
    {
        $this->resetExpense();
        $this->showExpenseModal = true;
    }

    public function editExpense(int $id): void
    {
        $e = DivisionExpense::where('group_id', $this->group->id)->with('shares')->findOrFail($id);
        abort_unless(auth()->user()->isAdmin() || ($e->created_by === auth()->id() && $e->status === 'pending'), 403);
        $this->resetExpense();
        $this->editingExpenseId = $e->id;
        foreach ($e->shares as $s) {
            $this->splitRows[$s->member_id] ??= ['on' => false, 'value' => ''];
        }
        $this->expenseDescription = $e->description;
        $this->expenseAmount = number_format((float) $e->amount, 2, '.', '');
        $this->expenseDate = $e->date->toDateString();
        $this->expenseCategory = $e->category;
        $this->paidBy = (string) $e->paid_by_member_id;
        $this->splitMode = $e->split_mode;
        $this->transactionId = (string) ($e->transaction_id ?? '');
        $this->expenseNotes = (string) $e->notes;
        foreach ($this->splitRows as $id => $row) {
            $share = $e->shares->firstWhere('member_id', $id);
            $value = $share ? ($e->split_mode === 'amount' ? number_format((float) $share->amount, 2, '.', '') : rtrim(rtrim(number_format((float) $share->input, 4, ',', ''), '0'), ',')) : '';
            $this->splitRows[$id] = ['on' => (bool) $share, 'value' => $value];
        }
        $this->showExpenseModal = true;
    }

    public function closeModals(): void
    {
        $this->showExpenseModal = false;
        $this->showSettleModal = false;
    }

    public function saveExpense(bool $another = false): void
    {
        $inputs = [];
        foreach ($this->splitRows as $id => $row) {
            if ($this->splitMode === 'equal') {
                if (! empty($row['on'])) {
                    $inputs[$id] = 1;
                }
            } elseif (trim((string) ($row['value'] ?? '')) !== '') {
                $inputs[$id] = $row['value'];
            }
        }
        if ($this->receipt) {
            $this->validate(['receipt' => 'file|mimes:pdf,jpg,jpeg,png|max:10240']);
        }
        try {
            $editing = $this->editingExpenseId ? DivisionExpense::where('group_id', $this->group->id)->findOrFail($this->editingExpenseId) : null;
            $created = $this->service()->saveExpense(auth()->user(), $this->group, [
                'description' => $this->expenseDescription, 'category' => $this->expenseCategory, 'date' => $this->expenseDate,
                'amount' => $this->expenseAmount, 'paid_by_member_id' => (int) $this->paidBy, 'split_mode' => $this->splitMode,
                'inputs' => $inputs, 'installments' => $this->installments, 'transaction_id' => $this->transactionId ?: null, 'notes' => $this->expenseNotes,
            ], $this->receipt, $editing);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $pending = $created->first()?->status === 'pending';
        $this->notice = ($editing ? 'Despesa atualizada.' : ($created->count() > 1 ? "Despesa lançada em {$created->count()} parcelas." : 'Despesa lançada.')).($pending ? ' Aguardando aprovação do Cassio.' : '');
        if ($another) {
            $keepDate = $this->expenseDate;
            $keepPayer = $this->paidBy;
            $this->resetExpense();
            $this->expenseDate = $keepDate;
            $this->paidBy = $keepPayer;
            $this->showExpenseModal = true;
        } else {
            $this->showExpenseModal = false;
        }
    }

    public function deleteExpense(int $id): void
    {
        $this->service()->deleteExpense(auth()->user(), DivisionExpense::where('group_id', $this->group->id)->findOrFail($id));
        $this->notice = 'Despesa excluída.';
    }

    public function decideExpense(int $id, bool $approve): void
    {
        $this->service()->decideExpense(auth()->user(), DivisionExpense::where('group_id', $this->group->id)->findOrFail($id), $approve);
        $this->notice = $approve ? 'Despesa aprovada.' : 'Despesa recusada.';
    }

    public function splitTotals(): array
    {
        $total = \App\Support\Money::toFloat($this->expenseAmount);
        $sum = 0.0;
        foreach ($this->splitRows as $row) {
            $v = trim((string) ($row['value'] ?? ''));
            if ($v === '') {
                continue;
            }
            $sum += $this->splitMode === 'amount' ? \App\Support\Money::toFloat($v) : (float) str_replace(',', '.', str_replace('.', '', $v));
        }

        return ['total' => $total, 'sum' => $sum];
    }

    // ---------- Acerto ----------

    public function openSettle(?int $from = null, ?int $to = null, int $cents = 0): void
    {
        $this->reset('settleNotes', 'settleAccount');
        $me = $this->me();
        $this->settleFrom = (string) ($from ?? $me?->id ?? '');
        $this->settleTo = (string) ($to ?? '');
        $this->settleAmount = $cents > 0 ? number_format($cents / 100, 2, '.', '') : '';
        $this->settleDate = now()->toDateString();
        $this->resetValidation();
        $this->showSettleModal = true;
    }

    public function saveSettle(): void
    {
        try {
            $this->service()->settle(auth()->user(), $this->group, ['from_member_id' => (int) $this->settleFrom, 'to_member_id' => (int) $this->settleTo,
                'amount' => $this->settleAmount, 'date' => $this->settleDate, 'bank_account_id' => $this->settleAccount ?: null, 'notes' => $this->settleNotes]);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->showSettleModal = false;
        $this->notice = 'Acerto registrado.'.($this->settleAccount ? ' Lançamento criado no Financeiro.' : '');
    }

    public function deleteSettlement(int $id): void
    {
        $this->service()->deleteSettlement(auth()->user(), DivisionSettlement::where('group_id', $this->group->id)->findOrFail($id));
        $this->notice = 'Acerto excluído (e o lançamento do Financeiro vinculado, se havia).';
    }

    // ---------- Participantes / configurações ----------

    public function addMember(): void
    {
        try {
            $this->service()->addMember(auth()->user(), $this->group, $this->memberName, $this->memberEmail ?: null, $this->memberPhone ?: null);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->reset('memberName', 'memberEmail', 'memberPhone');
        $this->notice = 'Participante adicionado.';
    }

    public function removeMember(int $memberId): void
    {
        $member = DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId);
        $this->reset('blockedMemberId', 'blockedReason');
        if ($reason = $this->service()->removalBlocker($member)) {
            $this->blockedMemberId = $member->id;
            $this->blockedReason = $reason;

            return;
        }
        try {
            $this->service()->removeMember(auth()->user(), $member);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->notice = $member->name.' foi removido do grupo.';
    }

    public function setMemberActive(int $memberId, bool $active): void
    {
        try {
            $member = DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId);
            $this->service()->setMemberActive(auth()->user(), $member, $active);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->reset('blockedMemberId', 'blockedReason');
        $this->notice = $active ? $member->name.' foi reativado.' : $member->name.' foi desativado: não aparece em novas despesas, e o histórico continua.';
    }

    public function editPhone(int $memberId): void
    {
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId);
        abort_unless(auth()->user()->isAdmin() || (int) $m->user_id === (int) auth()->id(), 403);
        $this->phoneEditId = $m->id;
        $this->phoneDraft = $m->phoneDisplay();
        $this->resetValidation('phone');
    }

    public function savePhone(): void
    {
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail((int) $this->phoneEditId);
        try {
            $this->service()->setMemberPhone(auth()->user(), $m, $this->phoneDraft);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->reset('phoneEditId', 'phoneDraft');
        $this->notice = 'Celular salvo.';
    }

    public function openCharge(int $memberId, int $cents): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId);
        if (! $m->phone || $cents <= 0) {
            return;
        }
        $this->chargeMemberId = $m->id;
        $this->chargeCents = $cents;
        $this->chargeText = $this->service()->chargeMessage(auth()->user(), $m->name, $cents, [$this->group->name], route('divisions.show', $this->group));
    }

    /** Chamado no clique de "Abrir WhatsApp" (o link abre no navegador; o sistema não envia nada). */
    public function logCharge(): void
    {
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail((int) $this->chargeMemberId);
        $this->service()->logCharge(auth()->user(), $m, $this->chargeCents);
        $this->reset('chargeMemberId', 'chargeCents', 'chargeText');
        $this->notice = 'Cobrança aberta no WhatsApp e registrada no histórico.';
    }

    public function invite(int $memberId): void
    {
        try {
            $this->inviteLink = $this->service()->inviteMember(auth()->user(), DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId));
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->notice = $this->inviteLink ? 'Link de convite gerado. Copie e envie para a pessoa (nenhum e-mail é enviado).' : 'Essa pessoa já tem conta: o módulo Divisões foi liberado para ela.';
    }

    public function openSettings(): void
    {
        $this->editName = $this->group->name;
        $this->editType = $this->group->type;
        $this->editRequiresApproval = $this->group->requires_approval;
        $this->showSettings = ! $this->showSettings;
    }

    public function saveSettings(): void
    {
        try {
            $this->service()->updateGroup(auth()->user(), $this->group, ['name' => $this->editName, 'type' => $this->editType, 'requires_approval' => $this->editRequiresApproval, 'notes' => $this->group->notes]);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->showSettings = false;
        $this->notice = 'Grupo atualizado.';
    }

    public function with(): array
    {
        $service = $this->service();
        $user = auth()->user();
        $this->group->load('members');
        $members = $this->group->members->keyBy('id');
        $net = $service->netBalances($this->group);
        $me = $this->me();
        $owner = $this->group->ownerMember();
        $linkable = collect();
        if ($user->isAdmin() && $this->showExpenseModal && $owner && (int) $this->paidBy === $owner->id) {
            $base = \Illuminate\Support\Carbon::parse($this->expenseDate ?: now());
            $used = DivisionExpense::whereNotNull('transaction_id')->when($this->editingExpenseId, fn ($q) => $q->where('id', '!=', $this->editingExpenseId))->pluck('transaction_id');
            $linkable = Transaction::where('type', 'expense')->whereBetween('date', [$base->copy()->subDays(45)->toDateString(), $base->copy()->addDays(45)->toDateString()])
                ->whereNotIn('id', $used)->with('bankAccount', 'creditCard')->orderByDesc('date')->limit(100)->get();
        }

        return [
            'isAdmin' => $user->isAdmin(),
            'invites' => $user->isAdmin() ? \App\Models\UserInvitation::whereIn('email', $members->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e)))->whereNull('accepted_at')->orderBy('id')->get()->keyBy('email') : collect(),
            'me' => $me,
            'owner' => $owner,
            'members' => $members,
            'formMembers' => $members->filter(function ($m) {
                if ($m->active) {
                    return true;
                }
                $e = $this->editingExpenseId ? DivisionExpense::with('shares')->find($this->editingExpenseId) : null;

                return $e && ($e->paid_by_member_id === $m->id || $e->shares->contains('member_id', $m->id));
            }),
            'net' => $net,
            'groupAudits' => $this->group->audits()->whereNull('expense_id')->with('user')->latest('id')->limit(30)->get(),
            'chargeMember' => $this->chargeMemberId ? $members[$this->chargeMemberId] ?? null : null,
            'transfers' => $service->simplify($net),
            'expenses' => $this->group->expenses()->with('payer', 'shares.member', 'audits.user', 'creator', 'transaction')
                ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q->where('status', '!=', 'rejected')->orWhere('created_by', $user->id)))
                ->orderByDesc('date')->orderByDesc('id')->get(),
            'settlements' => $this->group->settlements()->with('from', 'to', 'transaction')->orderByDesc('date')->get(),
            'statement' => $service->statement($this->group),
            'accounts' => $user->isAdmin() ? BankAccount::active()->orderBy('name')->get() : collect(),
            'ownerInvolved' => $owner && in_array((int) $owner->id, [(int) $this->settleFrom, (int) $this->settleTo], true),
            'linkable' => $linkable,
            'canLink' => $user->isAdmin() && $owner && (int) $this->paidBy === $owner->id,
            'totals' => $this->splitTotals(),
            'service' => $service,
        ];
    }
}; ?>

<x-slot name="header">Divisões · {{ $group->name }}</x-slot>

<div class="flex flex-col gap-md" data-division-show>
    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif
    @error('expense')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror

    <x-fx.card>
        <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('divisions.index') }}" class="text-xs text-primary-500">‹ Divisões</a>
                <h2 class="text-fs-20 font-semibold text-cryptex-text-primary">{{ $group->name }}</h2>
                <p class="text-sm text-mono-600">{{ $group->typeLabel() }} · {{ $members->count() }} participante(s) · despesas de participantes {{ $group->requires_approval ? 'precisam de aprovação' : 'entram direto' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($isAdmin)<button type="button" class="fx-btn fx-btn--standard" wire:click="openSettings">Configurar</button>@endif
                <button type="button" class="fx-btn fx-btn--standard" wire:click="openSettle">Registrar acerto</button>
                <button type="button" class="fx-btn fx-btn--primary" wire:click="newExpense"><span class="material-icons-outlined text-[18px]">add</span> Nova despesa</button>
            </div>
        </div>
        @if ($showSettings && $isAdmin)
            <form wire:submit="saveSettings" class="mt-4 grid grid-cols-1 gap-3 border-t border-mono-100 pt-4 md:grid-cols-3">
                <x-jr.input label="Nome" name="editName" wire:model="editName" />
                <div><label class="mb-2 block text-sm font-medium text-mono-600">Tipo</label><select wire:model="editType" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach (\App\Domains\Divisions\Models\DivisionGroup::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="editRequiresApproval"> Despesas de participantes precisam da minha aprovação</label>
                <div class="flex justify-end gap-2 md:col-span-3"><button type="button" class="fx-btn fx-btn--standard" wire:click="$set('showSettings', false)">Cancelar</button><button type="submit" class="fx-btn fx-btn--primary">Salvar</button></div>
            </form>
        @endif
    </x-fx.card>

    <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
        <x-fx.card data-balances>
            <h3 class="mb-3 text-fs-16 font-semibold">Saldos</h3>
            @foreach ($members as $m)
                @php($c = $net[$m->id] ?? 0)
                <div class="flex justify-between py-1 text-sm"><span>{{ $m->name }}@if ($m->is_owner) (você)@endif</span>
                    <span class="font-semibold {{ $c > 0 ? 'text-system-up' : ($c < 0 ? 'text-system-down' : 'text-mono-600') }}">{{ $c > 0 ? 'recebe' : ($c < 0 ? 'deve' : 'quitado') }}@if ($c) R$ {{ $service->money(abs($c)) }}@endif</span></div>
            @endforeach
            <h4 class="mt-4 mb-2 text-sm font-semibold text-mono-600">Quem paga quem (simplificado)</h4>
            @forelse ($transfers as $t)
                <div class="flex items-center justify-between gap-2 border-b border-mono-100 py-2 text-sm last:border-0" data-transfer>
                    <span><strong>{{ $members[$t['from']]->name }}</strong> paga <strong>{{ $members[$t['to']]->name }}</strong>: R$ {{ $service->money($t['cents']) }}</span>
                    @if ($isAdmin || in_array($me?->id, [$t['from'], $t['to']], true))
                        <button type="button" class="text-xs text-primary-500" wire:click="openSettle({{ $t['from'] }}, {{ $t['to'] }}, {{ $t['cents'] }})">Acertar</button>
                        @if ($isAdmin && $owner && $t['to'] === $owner->id)
                            @if ($members[$t['from']]->phone)
                                <button type="button" class="text-xs text-system-up" wire:click="openCharge({{ $t['from'] }}, {{ $t['cents'] }})" data-charge>Cobrar</button>
                            @else
                                <button type="button" class="cursor-not-allowed text-xs text-mono-400" disabled title="Cadastre o celular" data-charge-disabled>Cobrar</button>
                            @endif
                        @endif
                    @endif
                </div>
            @empty
                <p class="text-sm text-mono-600">Ninguém deve nada.</p>
            @endforelse
        </x-fx.card>

        <x-fx.card class="lg:col-span-2" data-members>
            <h3 class="mb-3 text-fs-16 font-semibold">Participantes</h3>
            @foreach ($members as $m)
                <div class="flex flex-col gap-1 border-b border-mono-100 py-2 text-sm last:border-0 sm:flex-row sm:items-center sm:justify-between" wire:key="m-{{ $m->id }}">
                    <span class="min-w-0"><strong>{{ $m->name }}</strong> <span class="text-mono-600">{{ $m->email }}</span>
                        @unless ($m->active)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs text-mono-600" data-member-inactive>Desativado</span>@endunless
                        @if ($m->phone)<span class="ml-1 text-xs text-mono-600" data-member-phone>{{ $m->phoneDisplay() }}</span>@endif
                        @if (($isAdmin || (int) $m->user_id === (int) auth()->id()) && $phoneEditId !== $m->id)<button type="button" class="ml-1 text-xs text-primary-500" wire:click="editPhone({{ $m->id }})" data-edit-phone>{{ $m->phone ? 'Editar celular' : 'Adicionar celular' }}</button>@endif
                        @if ($m->is_owner)<span class="ml-1 rounded-pill bg-primary-100 px-2 text-xs text-primary-500">Administrador</span>@elseif ($m->user_id)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs">Com acesso</span>@endif
                        @if ($isAdmin && ! $m->is_owner && ! $m->user_id && $m->email)<x-invite-link :invitation="$invites[mb_strtolower($m->email)] ?? null" :name="$m->name" :action="'invite('.$m->id.')'" />@endif</span>
                    @if ($isAdmin && ! $m->is_owner && ! $m->user_id && $m->email)
                        <button type="button" class="text-xs text-primary-500" wire:click="invite({{ $m->id }})" wire:confirm="Gerar um novo link para {{ $m->name }}? O link anterior deixa de valer." data-invite-member>Gerar link de convite</button>
                    @endif
                    @if ($isAdmin && ! $m->is_owner)
                        <span class="flex gap-3 text-xs">
                            @if ($m->active)
                                <button type="button" class="text-error" wire:click="removeMember({{ $m->id }})" wire:confirm="Remover {{ $m->name }} deste grupo?" data-remove-member>Remover</button>
                            @else
                                <button type="button" class="text-primary-500" wire:click="setMemberActive({{ $m->id }}, true)" data-reactivate-member>Reativar</button>
                            @endif
                        </span>
                    @endif
                </div>
                @if ($phoneEditId === $m->id)
                    <form wire:submit="savePhone" class="my-2 flex flex-wrap items-center gap-2 text-xs" data-phone-form>
                        <input type="tel" x-phone wire:model="phoneDraft" placeholder="(99) 99999-9999" maxlength="15" class="h-9 w-44 rounded-xl border-mono-200 text-xs">
                        <button type="submit" class="fx-btn fx-btn--standard">Salvar</button>
                        <button type="button" class="text-mono-600" wire:click="$set('phoneEditId', null)">Cancelar</button>
                        @error('phone')<span class="w-full text-error">{{ $message }}</span>@enderror
                    </form>
                @endif
                @if ($blockedMemberId === $m->id)
                    <div class="my-2 rounded-xl border border-mono-200 bg-mono-100 p-3 text-xs" data-remove-blocked>
                        <p class="mb-2">{{ $blockedReason }}</p>
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="setMemberActive({{ $m->id }}, false)" data-deactivate-member>Desativar participante</button>
                        <button type="button" class="ml-2 text-mono-600" wire:click="$set('blockedMemberId', null)">Cancelar</button>
                    </div>
                @endif
            @endforeach
            @if ($inviteLink)
                <div class="mt-3 rounded-xl border border-primary-100 bg-primary-100 p-3 text-sm" x-data>
                    <p class="mb-1 font-semibold">Link de convite (válido por alguns dias, só Divisões):</p>
                    <div class="flex gap-2"><input type="text" readonly value="{{ $inviteLink }}" class="h-10 flex-1 rounded-xl border-mono-200 text-xs" x-ref="l" data-invite-link>
                        <button type="button" class="fx-btn fx-btn--standard" x-on:click="navigator.clipboard.writeText($refs.l.value)">Copiar</button></div>
                </div>
            @endif
            @if ($isAdmin)
                <form wire:submit="addMember" class="mt-3 grid grid-cols-1 items-end gap-3 md:grid-cols-3">
                    <x-jr.input label="Nome" name="memberName" wire:model="memberName" />
                    <x-jr.input label="E-mail (para o convite)" name="memberEmail" type="email" wire:model="memberEmail" />
                    <x-jr.input label="Celular (opcional)" name="memberPhone" type="tel" x-phone placeholder="(99) 99999-9999" wire:model="memberPhone" />
                    <button type="submit" class="fx-btn fx-btn--standard h-12">Adicionar participante</button>
                </form>
            @endif
        </x-fx.card>
    </div>

    <x-fx.card data-expenses>
        <h3 class="mb-3 text-fs-16 font-semibold">Despesas</h3>
        @forelse ($expenses as $e)
            <div class="border-b border-mono-100 py-3 last:border-0" x-data="{ open: false }" wire:key="e-{{ $e->id }}">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" class="min-w-0 text-left" x-on:click="open = !open">
                        <div class="font-semibold text-mono-900">{{ $e->description }}
                            @if ($e->status !== 'approved')<span class="ml-1 rounded-pill {{ $e->status === 'pending' ? 'bg-primary-100 text-primary-500' : 'bg-mono-100 text-mono-600' }} px-2 text-xs">{{ \App\Domains\Divisions\Models\DivisionExpense::STATUS[$e->status] }}</span>@endif
                            @if ($e->transaction_id)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs" title="Vinculada ao Financeiro">Financeiro #{{ $e->transaction_id }}</span>@endif</div>
                        <div class="text-xs text-mono-600">{{ $e->date->format('d/m/Y') }} · {{ $e->categoryLabel() }} · pago por {{ $e->payer?->name }} · {{ \App\Domains\Divisions\Models\DivisionExpense::MODES[$e->split_mode] ?? '' }}</div>
                    </button>
                    <div class="flex items-center gap-3">
                        <strong>R$ {{ number_format((float) $e->amount, 2, ',', '.') }}</strong>
                        @if ($isAdmin && $e->status === 'pending')
                            <button type="button" class="text-xs text-error" wire:click="decideExpense({{ $e->id }}, false)">Recusar</button>
                            <button type="button" class="text-xs text-system-up" wire:click="decideExpense({{ $e->id }}, true)">Aprovar</button>
                        @endif
                        @if ($isAdmin || ($e->created_by === auth()->id() && $e->status === 'pending'))
                            <button type="button" class="text-xs text-primary-500" wire:click="editExpense({{ $e->id }})">Editar</button>
                            <button type="button" class="text-xs text-error" wire:click="deleteExpense({{ $e->id }})" wire:confirm="Excluir esta despesa?">Excluir</button>
                        @endif
                    </div>
                </div>
                <div x-show="open" x-cloak class="mt-2 grid grid-cols-1 gap-3 rounded-xl bg-mono-50 p-3 text-xs md:grid-cols-2">
                    <div><p class="mb-1 font-semibold">Divisão</p>
                        @foreach ($e->shares as $s)<div class="flex justify-between"><span>{{ $s->member?->name }}</span><span>R$ {{ number_format((float) $s->amount, 2, ',', '.') }}</span></div>@endforeach
                        @if ($e->receipt_path)<a class="mt-2 inline-block text-primary-500" href="{{ route('divisions.receipt', $e) }}">Ver comprovante</a>@endif
                        @if ($e->notes)<p class="mt-2 text-mono-600">{{ $e->notes }}</p>@endif</div>
                    <div data-history><p class="mb-1 font-semibold">Histórico</p>
                        @foreach ($e->audits as $a)<div>{{ $a->created_at?->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ $a->label() }} · {{ $a->user?->name ?? 'sistema' }}</div>@endforeach</div>
                </div>
            </div>
        @empty
            <p class="text-sm text-mono-600">Nenhuma despesa lançada.</p>
        @endforelse
    </x-fx.card>

    @if ($isAdmin && $chargeMember)
        @include('divisions.charge-modal', ['phone' => $chargeMember->phone, 'name' => $chargeMember->name, 'cents' => $chargeCents, 'service' => $service])
    @endif

    <x-fx.card data-group-history>
        <h3 class="mb-3 text-fs-16 font-semibold">Histórico do grupo</h3>
        @forelse ($groupAudits as $a)
            <div class="border-b border-mono-100 py-1 text-xs last:border-0">{{ $a->created_at?->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · <strong>{{ $a->label() }}</strong>@if (! empty($a->details['nome'])) · {{ $a->details['nome'] }}@endif @if (! empty($a->details['valor'])) · {{ $a->details['valor'] }}@endif · {{ $a->user?->name ?? 'sistema' }}</div>
        @empty
            <p class="text-sm text-mono-600">Sem registros.</p>
        @endforelse
    </x-fx.card>

    <x-fx.card data-statement>
        <div class="mb-3 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <h3 class="text-fs-16 font-semibold">Extrato do grupo</h3>
            <div class="flex flex-wrap items-end gap-2 text-xs" data-pdf-filter>
                <label>De<input type="date" wire:model.live="pdfFrom" class="ml-1 h-9 rounded-xl border-mono-200 text-xs"></label>
                <label>Até<input type="date" wire:model.live="pdfTo" class="ml-1 h-9 rounded-xl border-mono-200 text-xs"></label>
                <a href="{{ route('divisions.pdf', array_filter(['group' => $group->id, 'de' => $pdfFrom, 'ate' => $pdfTo])) }}" class="fx-btn fx-btn--standard" data-pdf-link><span class="material-icons-outlined text-[18px]">picture_as_pdf</span> Baixar PDF</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="border-b border-mono-100 text-mono-600"><th class="py-2">Data</th><th>Tipo</th><th>Descrição</th><th class="text-right">Valor</th>@foreach ($members as $m)<th class="text-right">{{ $m->name }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($statement as $r)
                        <tr class="border-b border-mono-100">
                            <td class="py-2">{{ $r['date']->format('d/m/Y') }}</td><td>{{ $r['kind'] }}</td><td>{{ $r['description'] }}</td><td class="text-right">R$ {{ $service->money($r['cents']) }}</td>
                            @foreach ($members as $m)@php($v = $r['running'][$m->id] ?? 0)<td class="text-right {{ $v > 0 ? 'text-system-up' : ($v < 0 ? 'text-system-down' : '') }}">{{ $service->money($v) }}</td>@endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ 4 + $members->count() }}" class="py-3 text-mono-600">Sem movimentos.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-mono-600">As colunas de cada pessoa mostram o saldo acumulado: positivo = tem a receber; negativo = deve.</p>
        @if ($settlements->isNotEmpty())
            <h4 class="mt-4 mb-2 text-sm font-semibold">Acertos</h4>
            @foreach ($settlements as $s)
                <div class="flex justify-between border-b border-mono-100 py-1 text-xs last:border-0"><span>{{ $s->date->format('d/m/Y') }} · {{ $s->from?->name }} pagou {{ $s->to?->name }} · R$ {{ number_format((float) $s->amount, 2, ',', '.') }}@if ($s->transaction_id) · Financeiro #{{ $s->transaction_id }}@endif</span>
                    @if ($isAdmin)<button type="button" class="text-error" wire:click="deleteSettlement({{ $s->id }})" wire:confirm="Excluir este acerto? O lançamento vinculado no Financeiro também será excluído.">Excluir</button>@endif</div>
            @endforeach
        @endif
    </x-fx.card>

    @if ($showExpenseModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" data-expense-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="closeModals" aria-label="Fechar modal"></button>
            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center border-b border-mono-100 px-6"><h3 class="text-lg font-bold text-mono-900">{{ $editingExpenseId ? 'Editar despesa' : 'Nova despesa' }}</h3></div>
                <form wire:submit="saveExpense" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="md:col-span-2"><x-jr.input label="Descrição *" icon="description" name="expenseDescription" wire:model="expenseDescription" /></div>
                            <x-jr.input label="Valor *" icon="payments" name="expenseAmount" type="text" x-money wire:model.live.debounce.400ms="expenseAmount" />
                            <x-jr.input label="Data *" icon="event" type="date" name="expenseDate" wire:model.live="expenseDate" />
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Categoria</label>
                                <select wire:model="expenseCategory" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach (\App\Domains\Divisions\Models\DivisionExpense::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Quem pagou *</label>
                                <select wire:model.live="paidBy" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach ($formMembers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                                @error('paidBy')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror</div>
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Como dividir</label>
                                <select wire:model.live="splitMode" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach (\App\Domains\Divisions\Models\DivisionExpense::MODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            @unless ($editingExpenseId)
                                <x-jr.input label="Parcelas" icon="view_week" type="number" min="1" max="60" name="installments" wire:model="installments" helper="1 = sem parcelamento. Cada parcela cai um mês depois." />
                            @endunless
                            <div class="md:col-span-2 rounded-xl border border-mono-100 p-3" data-split-rows>
                                @foreach ($formMembers as $m)
                                    <div class="flex items-center justify-between gap-3 py-1 text-sm" wire:key="sr-{{ $m->id }}-{{ $splitMode }}">
                                        @if ($splitMode === 'equal')
                                            <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="splitRows.{{ $m->id }}.on"> {{ $m->name }}</label>
                                        @else
                                            <span>{{ $m->name }}</span>
                                            @if ($splitMode === 'amount')
                                                <input type="text" x-money wire:model.live.debounce.400ms="splitRows.{{ $m->id }}.value" class="h-10 w-40 rounded-xl border-mono-200 text-right text-sm">
                                            @else
                                                <input type="text" x-decimal="{{ $splitMode === 'percent' ? 3 : 4 }}" wire:model.live.debounce.400ms="splitRows.{{ $m->id }}.value" placeholder="{{ $splitMode === 'percent' ? '%' : 'cotas' }}" class="h-10 w-40 rounded-xl border-mono-200 text-right text-sm">
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                                <p class="mt-2 text-xs text-mono-600" data-split-sum>
                                    @if ($splitMode === 'percent') Soma: {{ number_format($totals['sum'], 2, ',', '.') }}% de 100%
                                    @elseif ($splitMode === 'amount') Soma: R$ {{ number_format($totals['sum'], 2, ',', '.') }} de R$ {{ number_format($totals['total'], 2, ',', '.') }}
                                    @elseif ($splitMode === 'shares') Total de cotas: {{ number_format($totals['sum'], 2, ',', '.') }}
                                    @else Dividido em partes iguais entre os marcados. @endif
                                </p>
                                @error('splitRows')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                                @error('splitMode')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                            </div>
                            @if ($canLink)
                                <div class="md:col-span-2"><label class="mb-2 block text-sm font-medium text-mono-600">Já está no Financeiro? Vincular a um lançamento (opcional)</label>
                                    <select wire:model="transactionId" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm" data-link-transaction>
                                        <option value="">Não vincular</option>
                                        @foreach ($linkable as $tx)<option value="{{ $tx->id }}">{{ $tx->date->format('d/m/Y') }} · {{ $tx->description }} · R$ {{ number_format((float) $tx->amount, 2, ',', '.') }} · {{ $tx->creditCard?->name ?? $tx->bankAccount?->name }}</option>@endforeach
                                    </select>
                                    @error('transactionId')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror</div>
                            @endif
                            <details class="md:col-span-2 rounded-xl border border-mono-100 p-3 text-sm" data-receipt>
                                <summary class="cursor-pointer text-mono-600">Comprovante e observações (opcional)</summary>
                                <div class="mt-3 space-y-3">
                                    <input type="file" wire:model="receipt" accept=".pdf,.jpg,.jpeg,.png">
                                    @error('receipt')<p class="text-xs text-error">{{ $message }}</p>@enderror
                                    <textarea wire:model="expenseNotes" rows="2" class="w-full rounded-2xl border border-mono-200 px-4 py-2 text-sm" placeholder="Observações"></textarea>
                                </div>
                            </details>
                            @error('expenseAmount')<p class="text-xs text-error md:col-span-2">{{ $message }}</p>@enderror
                            @error('expenseDescription')<p class="text-xs text-error md:col-span-2">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="closeModals">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                        @unless ($editingExpenseId)<button type="button" class="fx-btn fx-btn--primary" wire:click="saveExpense(true)">Salvar e novo</button>@endunless
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showSettleModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" data-settle-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="closeModals" aria-label="Fechar modal"></button>
            <div class="relative flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center border-b border-mono-100 px-6"><h3 class="text-lg font-bold text-mono-900">Registrar acerto</h3></div>
                <form wire:submit="saveSettle" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Quem pagou</label><select wire:model.live="settleFrom" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm"><option value="">Selecione</option>@foreach ($members as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                                @error('settleFrom')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror</div>
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Quem recebeu</label><select wire:model.live="settleTo" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm"><option value="">Selecione</option>@foreach ($members as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
                            <x-jr.input label="Valor *" icon="payments" name="settleAmount" type="text" x-money wire:model="settleAmount" />
                            <x-jr.input label="Data *" icon="event" type="date" name="settleDate" wire:model="settleDate" />
                        </div>
                        @if ($isAdmin && $ownerInvolved)
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Conta do Financeiro *</label>
                                <select wire:model="settleAccount" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm" data-settle-account><option value="">Selecione</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                                <p class="mt-1 text-xs text-mono-600">Cria o lançamento no Financeiro já vinculado a este grupo.</p>
                                @error('settleAccount')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror</div>
                        @endif
                        <x-jr.input label="Observação" name="settleNotes" wire:model="settleNotes" />
                        @error('settleAmount')<p class="text-xs text-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="closeModals">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

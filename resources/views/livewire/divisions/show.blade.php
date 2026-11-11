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

    public string $paymentMethod = '';

    public string $payAccount = '';

    public string $payCard = '';

    public bool $showNewCategory = false;

    public string $newCategoryLabel = '';

    public string $expenseNotes = '';

    /** Grupo ligado a um bem: tipo da despesa (expense|purchase) e, na compra, quem pagou a mais (advance|increase). */
    public string $expenseKind = 'expense';

    public string $purchaseMode = 'advance';

    /** Categoria do Financeiro do Cassio para a parte dele (só o Cassio vê). */
    public $ownerCategoryId = null;

    /** "Trocar categoria" de uma despesa já lançada (só a categoria do Cassio). */
    public ?int $categoryExpenseId = null;

    public $quickCategoryId = null;

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

    public string $personSearch = '';

    public ?int $phoneEditId = null;

    public string $phoneDraft = '';

    public ?int $nameEditId = null;

    public string $memberNameDraft = '';

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
        // Vindo de Bens › "+ Pagamento" de um bem em sociedade: abre a despesa já marcada como Compra do bem.
        if (request()->query('nova') === 'compra' && auth()->user()->isAdmin() && $this->linkedProperty()) {
            $this->newExpense();
            $this->expenseKind = 'purchase';
            $last = DivisionExpense::where('group_id', $group->id)->where('kind', 'purchase')->latest('date')->first();
            if ($last) {
                $this->expenseCategory = $last->category;
            }
        }
        // Vindo do Financeiro › "Trocar categoria" no lançamento "sua parte" / "Divisões".
        $ask = (int) request()->query('categoria');
        if ($ask > 0 && auth()->user()->isAdmin() && DivisionExpense::where('group_id', $group->id)->whereKey($ask)->exists()) {
            $this->openCategory($ask);
        }
    }

    private function linkedProperty(): ?\App\Domains\Assets\Models\Property
    {
        return \App\Domains\Assets\Models\Property::where('division_group_id', $this->group->id)->first();
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
        $this->reset('editingExpenseId', 'expenseDescription', 'expenseAmount', 'expenseNotes', 'receipt', 'transactionId', 'paymentMethod', 'payAccount', 'payCard', 'showNewCategory', 'newCategoryLabel', 'expenseKind', 'purchaseMode');
        $this->expenseDate = \App\Support\BusinessDate::today();
        $this->expenseCategory = 'outros';
        $this->ownerCategoryId = auth()->user()->isAdmin() ? $this->service()->financeCategoryId('outros') : null;
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
        $this->ownerCategoryId = $e->owner_category_id;
        $this->paidBy = (string) $e->paid_by_member_id;
        $this->splitMode = $e->split_mode;
        $this->transactionId = (string) ($e->transaction_id ?? '');
        $tx = $e->transaction_id ? Transaction::find($e->transaction_id) : null;
        if ($tx && $tx->source_type === 'division_expense') {
            $this->paymentMethod = $tx->credit_card_id ? 'card' : 'account';
            $this->payAccount = (string) ($tx->bank_account_id ?? '');
            $this->payCard = (string) ($tx->credit_card_id ?? '');
            $this->transactionId = '';
        }
        $this->expenseNotes = (string) $e->notes;
        $this->expenseKind = $e->kind ?? 'expense';
        $this->purchaseMode = $e->purchase_mode ?? 'advance';
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
            $ownerPaid = auth()->user()->isAdmin() && (int) $this->paidBy === (int) $this->group->ownerMember()?->id;
            $method = $ownerPaid && in_array($this->paymentMethod, ['account', 'card'], true) ? $this->paymentMethod : null;
            $created = $this->service()->saveExpense(auth()->user(), $this->group, [
                'description' => $this->expenseDescription, 'category' => $this->expenseCategory, 'date' => $this->expenseDate,
                'amount' => $this->expenseAmount, 'paid_by_member_id' => (int) $this->paidBy, 'split_mode' => $this->splitMode,
                'inputs' => $inputs, 'installments' => $this->installments, 'transaction_id' => $method ? null : ($this->transactionId ?: null), 'notes' => $this->expenseNotes,
                'kind' => $this->expenseKind, 'purchase_mode' => $this->purchaseMode,
                'owner_category_id' => $this->ownerCategoryId ?: null,
                'payment_method' => $method, 'bank_account_id' => $method === 'account' ? (int) $this->payAccount : null, 'credit_card_id' => $method === 'card' ? (int) $this->payCard : null,
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

    /** Ao trocar a categoria do grupo, a sua acompanha a sugestão enquanto você não escolheu outra. */
    public function updatingExpenseCategory($value): void
    {
        if (! auth()->user()->isAdmin()) {
            return;
        }
        $prev = $this->service()->financeCategoryId($this->expenseCategory);
        if (! $this->ownerCategoryId || (int) $this->ownerCategoryId === (int) $prev) {
            $this->ownerCategoryId = $this->service()->financeCategoryId((string) $value);
        }
    }

    public function openCategory(int $id): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $e = DivisionExpense::where('group_id', $this->group->id)->findOrFail($id);
        $this->resetValidation();
        $this->categoryExpenseId = $e->id;
        $this->quickCategoryId = $e->owner_category_id;
    }

    public function saveCategory(): void
    {
        $e = DivisionExpense::where('group_id', $this->group->id)->findOrFail((int) $this->categoryExpenseId);
        try {
            $this->service()->setOwnerCategory(auth()->user(), $e, $this->quickCategoryId ?: null);
        } catch (ValidationException $ex) {
            $this->fail($ex);

            return;
        }
        $name = $e->fresh()->ownerCategory?->name;
        $this->notice = $name ? "Categoria da sua parte em \"{$e->description}\": {$name}." : "A despesa \"{$e->description}\" ficou sem categoria.";
        $this->categoryExpenseId = null;
    }

    public function createCategory(): void
    {
        try {
            $this->expenseCategory = $this->service()->createCategory(auth()->user(), $this->newCategoryLabel);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->newCategoryLabel = '';
        $this->showNewCategory = false;
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
        $this->settleDate = \App\Support\BusinessDate::today();
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
        $this->resetErrorBag(['memberName', 'memberEmail', 'memberPhone']);
        if (trim($this->memberEmail) === '') {
            $this->addError('memberEmail', 'Informe o e-mail (o convite vai para ele).');

            return;
        }
        try {
            $m = $this->service()->addMember(auth()->user(), $this->group, $this->memberName, $this->memberEmail ?: null, $this->memberPhone ?: null);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->reset('memberName', 'memberEmail', 'memberPhone');
        $this->notice = $m->isPending() ? $m->name.' foi incluído e está aguardando aprovação do gestor. Fale com o gestor para aprovar.' : 'Participante adicionado.';
    }

    public function decideMember(int $memberId, bool $approve): void
    {
        try {
            $link = $this->service()->decideMember(auth()->user(), DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId), $approve);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->inviteLink = $link;
        $this->notice = $approve ? ($link ? 'Participante aprovado. Link de convite gerado: copie e envie para a pessoa.' : 'Participante aprovado.') : 'Pedido recusado: a pessoa foi removida do grupo.';
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

    public function editName(int $memberId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail($memberId);
        $this->nameEditId = $m->id;
        $this->memberNameDraft = $m->name;
        $this->resetValidation('memberNameDraft');
    }

    public function saveName(): void
    {
        $m = DivisionMember::where('group_id', $this->group->id)->findOrFail((int) $this->nameEditId);
        try {
            $this->service()->setMemberName(auth()->user(), $m, $this->memberNameDraft);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->reset('nameEditId', 'memberNameDraft');
        $this->notice = 'Nome salvo.';
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

    public function addExisting(string $key): void
    {
        try {
            $m = $this->service()->addExistingPerson(auth()->user(), $this->group, $key);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }
        $this->personSearch = '';
        $this->notice = $m->user_id ? $m->name.' foi adicionado e já entra com a conta que tem (nenhum convite novo).' : $m->name.' foi adicionado ao grupo.';
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
            $amt = \App\Support\Money::toFloat($this->expenseAmount);
            $linkable = Transaction::where('type', 'expense')->whereBetween('date', [$base->copy()->subDays(10)->toDateString(), $base->copy()->addDays(10)->toDateString()])
                ->when($amt > 0, fn ($q) => $q->whereBetween('amount', [round($amt * 0.9, 2), round($amt * 1.1, 2)]))
                ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'division_expense'))
                ->whereNotIn('id', $used)->with('bankAccount', 'creditCard')->orderByDesc('date')->limit(100)->get();
        }

        return [
            'isAdmin' => $user->isAdmin(),
            'invites' => $user->isAdmin() ? \App\Models\UserInvitation::visible()->whereIn('email', $members->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e)))->whereNull('accepted_at')->orderBy('id')->get()->keyBy('email') : collect(),
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
            'peopleFound' => ($user->isAdmin() || $me) ? $service->searchExistingPeople($this->group, $this->personSearch, 8, $user->isAdmin()) : [],
            'groupAudits' => $this->group->audits()->whereNull('expense_id')->with('user')->latest('id')->limit(30)->get(),
            'chargeMember' => $this->chargeMemberId ? $members[$this->chargeMemberId] ?? null : null,
            'transfers' => $service->simplify($net),
            'expenses' => $this->group->expenses()->with(array_merge(['payer', 'shares.member', 'audits.user', 'creator', 'transaction'], $user->isAdmin() ? ['ownerCategory'] : []))
                ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q->where('status', '!=', 'rejected')->orWhere('created_by', $user->id)))
                ->orderByDesc('date')->orderByDesc('id')->get(),
            'settlements' => $this->group->settlements()->with('from', 'to', 'transaction')->orderByDesc('date')->get(),
            'statement' => $service->statement($this->group),
            'accounts' => $user->isAdmin() ? BankAccount::active()->orderBy('name')->get() : collect(),
            'ownerInvolved' => $owner && in_array((int) $owner->id, [(int) $this->settleFrom, (int) $this->settleTo], true),
            'linkable' => $linkable,
            'cards' => $user->isAdmin() ? \App\Domains\Banking\Models\CreditCard::active()->orderBy('name')->get() : collect(),
            'categories' => DivisionExpense::categories(),
            'canLink' => $user->isAdmin() && $owner && (int) $this->paidBy === $owner->id,
            'totals' => $this->splitTotals(),
            'linkedProperty' => $this->linkedProperty(),
            'service' => $service,
            'ownerCategories' => $user->isAdmin() ? $service->ownerCategoryOptions() : collect(),
            'categoryExpense' => $user->isAdmin() && $this->categoryExpenseId ? DivisionExpense::with('payer', 'ownerCategory')->find($this->categoryExpenseId) : null,
        ];
    }
}; ?>

<x-slot name="header">Divisões · {{ $group->name }}</x-slot>

<div class="flex flex-col gap-md" data-division-show>
    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif
    @error('expense')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @if ($isAdmin)
        @php($missingCategory = $expenses->filter(fn ($e) => $service->needsOwnerCategory($e, $owner)))
        @if ($missingCategory->isNotEmpty())
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" data-missing-banner>
                <p class="font-semibold">{{ $missingCategory->count() === 1 ? '1 despesa está' : $missingCategory->count().' despesas estão' }} sem a sua categoria no Financeiro</p>
                <p class="mt-1 text-xs">Enquanto não escolher, a sua parte aparece como "sem categoria" nos relatórios. Só você vê esta categoria.</p>
                <div class="mt-2 flex flex-col gap-1">
                    @foreach ($missingCategory as $e)
                        <button type="button" class="flex min-h-[40px] items-center justify-between gap-2 rounded-xl bg-mono-white px-3 py-2 text-left text-xs" wire:click="openCategory({{ $e->id }})" data-missing-item="{{ $e->id }}">
                            <span class="min-w-0"><span class="block truncate font-semibold">{{ $e->description }}</span><span class="block text-mono-600">{{ $e->date->format('d/m/Y') }} · pago por {{ $e->payer?->name }}</span></span>
                            <span class="shrink-0 font-semibold text-primary-500">Escolher categoria</span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <x-fx.card>
        <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('divisions.index') }}" class="text-xs text-primary-500">‹ Divisões</a>
                <h2 class="text-fs-20 font-semibold text-cryptex-text-primary">{{ $group->name }}</h2>
                <p class="text-sm text-mono-600">{{ $group->typeLabel() }} · {{ $members->count() }} participante(s) · despesas de participantes {{ $group->requires_approval ? 'precisam de aprovação' : 'entram direto' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($isAdmin)<button type="button" class="fx-btn fx-btn--standard" wire:click="openSettings">Configurar</button>@endif
                <div class="hidden lg:block" data-desktop-actions><span style="display:inline-flex;gap:.5rem">
                    <button type="button" class="fx-btn fx-btn--standard" wire:click="openSettle">Registrar acerto</button>
                    <button type="button" class="fx-btn fx-btn--primary" wire:click="newExpense"><span class="material-icons-outlined text-[18px]">add</span> Nova despesa</button>
                </span></div>
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

    @if ($me)
        @php($myNet = (int) ($net[$me->id] ?? 0))
        @php($myTransfers = collect($transfers)->filter(fn ($t) => in_array($me->id, [$t['from'], $t['to']], true))->values())
        <x-fx.card data-my-summary x-data="{ open: false }">
            <button type="button" class="flex w-full items-center justify-between gap-3 text-left" x-on:click="open = !open" data-summary-toggle>
                @if ($myNet > 0)
                    <span class="text-fs-18 font-semibold text-mono-900" data-summary-total data-sign="pos"><span class="text-green-600" data-amount>Devem a você R$ {{ $service->money($myNet) }}</span> no total</span>
                @elseif ($myNet < 0)
                    <span class="text-fs-18 font-semibold text-mono-900" data-summary-total data-sign="neg"><span class="text-red-600" data-amount>Você deve R$ {{ $service->money(abs($myNet)) }}</span> no total</span>
                @else
                    <span class="text-fs-18 font-semibold text-mono-600" data-summary-total data-sign="zero">Você está quitado neste grupo</span>
                @endif
                @if ($myTransfers->isNotEmpty())<span class="material-icons-outlined text-mono-600" x-text="open ? 'expand_less' : 'expand_more'">expand_more</span>@endif
            </button>
            <ul x-show="open" x-cloak class="mt-3 space-y-1 border-t border-mono-100 pt-3 text-sm" data-summary-list>
                @foreach ($myTransfers as $t)
                    @if ($t['to'] === $me->id)
                        <li class="text-mono-900" data-summary-row data-sign="pos"><strong>{{ $members[$t['from']]->name }}</strong> <span class="text-green-600" data-amount>deve R$ {{ $service->money($t['cents']) }}</span> a você</li>
                    @else
                        <li class="text-mono-900" data-summary-row data-sign="neg"><span class="text-red-600" data-amount>Você deve R$ {{ $service->money($t['cents']) }}</span> a <strong>{{ $members[$t['to']]->name }}</strong></li>
                    @endif
                @endforeach
            </ul>
            <div class="mt-4 flex flex-wrap gap-2">
                @php($firstDebt = $myTransfers->firstWhere('from', $me->id))
                @if ($firstDebt)
                    <button type="button" class="fx-btn fx-btn--primary" wire:click="openSettle({{ $firstDebt['from'] }}, {{ $firstDebt['to'] }}, {{ $firstDebt['cents'] }})" data-btn-settle>Quitar contas</button>
                @else
                    <button type="button" class="fx-btn fx-btn--primary" wire:click="openSettle" data-btn-settle>Acertar</button>
                @endif
                <a href="#saldos" class="fx-btn fx-btn--standard" data-btn-balances>Saldos</a>
            </div>
        </x-fx.card>
    @else
        <div class="flex gap-2"><button type="button" class="fx-btn fx-btn--standard" wire:click="openSettle">Registrar acerto</button></div>
    @endif

    <div class="lg:hidden">@include('divisions.expense-list', ['variant' => 'm'])</div>

    <div id="saldos" class="grid grid-cols-1 gap-md lg:grid-cols-3 scroll-mt-24">
        <x-fx.card data-balances>
            <h3 class="mb-3 text-fs-16 font-semibold">Saldos</h3>
            @foreach ($members as $m)
                @php($c = $net[$m->id] ?? 0)
                <div class="flex justify-between py-1 text-sm"><span>{{ $m->name }}@if ($m->is_owner) (você)@endif</span>
                    <span class="font-semibold {{ $c > 0 ? 'text-green-600' : ($c < 0 ? 'text-red-600' : 'text-mono-600') }}">{{ $c > 0 ? 'recebe' : ($c < 0 ? 'deve' : 'quitado') }}@if ($c) R$ {{ $service->money(abs($c)) }}@endif</span></div>
            @endforeach
            <h4 class="mt-4 mb-2 text-sm font-semibold text-mono-600">Quem paga quem (proporcional aos créditos)</h4>
            @forelse ($transfers as $t)
                <div class="flex items-center justify-between gap-2 border-b border-mono-100 py-2 text-sm last:border-0" data-transfer>
                    <span><strong>{{ $members[$t['from']]->name }}</strong> paga <strong>{{ $members[$t['to']]->name }}</strong>: R$ {{ $service->money($t['cents']) }}</span>
                    @if ($isAdmin || in_array($me?->id, [$t['from'], $t['to']], true))
                        <button type="button" class="text-xs text-primary-500" wire:click="openSettle({{ $t['from'] }}, {{ $t['to'] }}, {{ $t['cents'] }})">Acertar</button>
                        @if ($isAdmin && $owner && $t['to'] === $owner->id)
                            @if ($members[$t['from']]->phone)
                                <button type="button" class="text-xs text-green-600" wire:click="openCharge({{ $t['from'] }}, {{ $t['cents'] }})" data-charge>Cobrar</button>
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
                        @if ($m->isPending())<span class="ml-1 rounded-pill bg-primary-100 px-2 text-xs text-primary-500" data-member-pending>Aguardando aprovação do gestor</span><span class="ml-1 text-xs text-mono-600">Fale com o gestor para aprovar.</span>
                        @elseif (! $m->active)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs text-mono-600" data-member-inactive>Desativado</span>@endif
                        @if ($m->phone)<span class="ml-1 text-xs text-mono-600" data-member-phone>{{ $m->phoneDisplay() }}</span>@endif
                        @if (($isAdmin || (int) $m->user_id === (int) auth()->id()) && $phoneEditId !== $m->id)<button type="button" class="ml-1 text-xs text-primary-500" wire:click="editPhone({{ $m->id }})" data-edit-phone>{{ $m->phone ? 'Editar celular' : 'Adicionar celular' }}</button>@endif
                        @if ($isAdmin && ! $m->is_owner && $nameEditId !== $m->id)<button type="button" class="ml-1 text-xs text-primary-500" wire:click="editName({{ $m->id }})" data-edit-name>Editar nome</button>@endif
                        @if ($m->is_owner)<span class="ml-1 rounded-pill bg-primary-100 px-2 text-xs text-primary-500">Administrador</span>@elseif ($m->user_id)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs">Com acesso</span>@endif
                        @if ($isAdmin && ! $m->is_owner && ! $m->user_id && $m->email && ! $m->isPending())<x-invite-link :invitation="$invites[mb_strtolower($m->email)] ?? null" :name="$m->name" :action="'invite('.$m->id.')'" />@endif</span>
                    @if ($isAdmin && $m->isPending())
                        <span class="flex gap-3 text-xs" data-member-decide>
                            <button type="button" class="text-error" wire:click="decideMember({{ $m->id }}, false)" wire:confirm="Recusar e remover {{ $m->name }}?">Recusar</button>
                            <button type="button" class="font-semibold text-green-600" wire:click="decideMember({{ $m->id }}, true)">Aprovar</button>
                        </span>
                    @endif
                    @if ($isAdmin && ! $m->is_owner && ! $m->user_id && $m->email && ! $m->isPending())
                        <button type="button" class="text-xs text-primary-500" wire:click="invite({{ $m->id }})" wire:confirm="Gerar um novo link para {{ $m->name }}? O link anterior deixa de valer." data-invite-member>Gerar link de convite</button>
                    @endif
                    @if ($isAdmin && ! $m->is_owner && ! $m->isPending())
                        <span class="flex gap-3 text-xs">
                            @if ($m->active)
                                <button type="button" class="text-error" wire:click="removeMember({{ $m->id }})" wire:confirm="Remover {{ $m->name }} deste grupo?" data-remove-member>Remover</button>
                            @else
                                <button type="button" class="text-primary-500" wire:click="setMemberActive({{ $m->id }}, true)" data-reactivate-member>Reativar</button>
                            @endif
                        </span>
                    @endif
                </div>
                @if ($isAdmin && $nameEditId === $m->id)
                    <form wire:submit="saveName" class="my-2 flex flex-wrap items-center gap-2 text-xs" data-name-form>
                        <input type="text" wire:model="memberNameDraft" maxlength="120" class="h-9 w-56 rounded-xl border-mono-200 text-xs">
                        <button type="submit" class="fx-btn fx-btn--standard">Salvar</button>
                        <button type="button" class="text-mono-600" wire:click="$set('nameEditId', null)">Cancelar</button>
                        @error('memberNameDraft')<span class="w-full text-error">{{ $message }}</span>@enderror
                    </form>
                @endif
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
            @if ($isAdmin || $me)
                <div class="mt-4 rounded-xl border border-mono-100 p-3" data-add-existing>
                    <p class="mb-2 text-sm font-semibold">Adicionar pessoa já cadastrada</p>
                    <input type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Buscar por nome, e-mail ou celular" class="h-11 w-full rounded-pill border border-mono-200 px-4 text-sm" data-person-search>
                    @error('personSearch')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                    @if (mb_strlen(trim($personSearch)) >= 2)
                        <ul class="mt-2 divide-y divide-mono-100 text-sm">
                            @forelse ($peopleFound as $p)
                                <li class="flex items-center justify-between gap-2 py-2" wire:key="pf-{{ $p['key'] }}">
                                    <span class="min-w-0"><strong>{{ $p['name'] }}</strong>
                                        <span class="text-xs text-mono-600">{{ $p['email'] ?? 'sem e-mail' }}@if ($p['phone']) · {{ \App\Support\BrazilianDocuments::formatPhone(substr($p['phone'], 3)) }}@endif · {{ $p['has_account'] ? 'Já tem conta' : $p['source'] }}</span></span>
                                    <button type="button" class="fx-btn fx-btn--standard shrink-0" wire:click="addExisting(@js($p['key']))" data-add-person>Adicionar</button>
                                </li>
                            @empty
                                <li class="py-2 text-xs text-mono-600">Ninguém encontrado. Use "Adicionar novo participante" abaixo.</li>
                            @endforelse
                        </ul>
                    @endif
                </div>
                <p class="mt-4 text-sm font-semibold">Adicionar novo participante</p>
                <form wire:submit="addMember" class="mt-3 grid grid-cols-1 items-end gap-3 md:grid-cols-3">
                    <x-jr.input label="E-mail *" name="memberEmail" type="email" wire:model="memberEmail" />
                    <x-jr.input label="Apelido (opcional)" name="memberName" wire:model="memberName" helper="Vira o nome real quando a pessoa completar o cadastro." />
                    <x-jr.input label="Celular (opcional)" name="memberPhone" type="tel" x-phone placeholder="(99) 99999-9999" wire:model="memberPhone" />
                    <button type="submit" class="fx-btn fx-btn--standard h-12">Adicionar participante</button>
                </form>
                @unless ($isAdmin)<p class="mt-2 text-xs text-mono-600" data-external-note>Pessoa nova (sem cadastro no sistema) fica aguardando aprovação do gestor antes de entrar nas divisões.</p>@endunless
            @endif
        </x-fx.card>
    </div>

    <div class="hidden lg:block">@include('divisions.expense-list', ['variant' => 'd'])</div>


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
                            @foreach ($members as $m)@php($v = $r['running'][$m->id] ?? 0)<td class="text-right {{ $v > 0 ? 'text-green-600' : ($v < 0 ? 'text-red-600' : '') }}">{{ $service->money($v) }}</td>@endforeach
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
                <div class="flex justify-between border-b border-mono-100 py-1 text-xs last:border-0"><span>{{ $s->date->format('d/m/Y') }} · {{ $s->from?->name }} pagou {{ $s->to?->name }} · R$ {{ number_format((float) $s->amount, 2, ',', '.') }}@if ($isAdmin && $s->transaction_id) · Financeiro #{{ $s->transaction_id }}@endif</span>
                    @if ($isAdmin)<button type="button" class="text-error" wire:click="deleteSettlement({{ $s->id }})" wire:confirm="Excluir este acerto? O lançamento vinculado no Financeiro também será excluído.">Excluir</button>@endif</div>
            @endforeach
        @endif
    </x-fx.card>

    @if ($categoryExpense)
        <div class="fixed inset-0 z-modal flex items-end justify-center px-0 sm:items-center sm:px-4" data-category-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="$set('categoryExpenseId', null)" aria-label="Fechar"></button>
            <div class="relative w-full max-w-lg rounded-t-2xl border border-mono-100 bg-mono-white p-5 shadow-elevated sm:rounded-2xl">
                <h3 class="text-lg font-bold text-mono-900">Trocar categoria</h3>
                <p class="mt-1 text-sm text-mono-600">{{ $categoryExpense->description }} · {{ $categoryExpense->date->format('d/m/Y') }} · pago por {{ $categoryExpense->payer?->name }}</p>
                @if ($categoryExpense->isPurchase())
                    <p class="mt-3 rounded-xl bg-mono-50 p-3 text-sm">Compra do bem: a sua parte fica sempre em "Aquisição de bem".</p>
                    <div class="mt-4 flex justify-end"><button type="button" class="fx-btn fx-btn--standard" wire:click="$set('categoryExpenseId', null)">Fechar</button></div>
                @else
                    <div class="mt-3"><x-banking.category-picker :categories="$ownerCategories" :selected-id="$quickCategoryId" model="quickCategoryId" id="division-quick-category" label="Sua categoria no Financeiro" placeholder="Sem categoria" /></div>
                    @error('ownerCategoryId')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-mono-600">Muda só a categoria da sua parte no Financeiro. Valor, saldos e a despesa dos outros participantes continuam iguais.</p>
                    <div class="mt-4 flex justify-end gap-2"><button type="button" class="fx-btn fx-btn--standard" wire:click="$set('categoryExpenseId', null)">Cancelar</button><button type="button" class="fx-btn fx-btn--primary" wire:click="saveCategory" data-save-category>Salvar</button></div>
                @endif
            </div>
        </div>
    @endif

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
                            <div data-category-field><div class="mb-2 flex items-center justify-between"><label class="text-sm font-medium text-mono-600">Categoria</label>
                                    <button type="button" class="text-xs font-semibold text-primary-500" wire:click="$toggle('showNewCategory')" data-new-category>+ Nova categoria</button></div>
                                <select wire:model="expenseCategory" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach ($categories as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                                @if ($showNewCategory)
                                    <div class="mt-2 flex gap-2" data-new-category-form><input type="text" wire:model="newCategoryLabel" wire:keydown.enter.prevent="createCategory" placeholder="Nome da nova categoria" class="h-10 min-w-0 flex-1 rounded-xl border-mono-200 text-sm">
                                        <button type="button" class="fx-btn fx-btn--primary" wire:click="createCategory">Criar</button></div>
                                    @error('newCategoryLabel')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                                @endif
                                @if ($isAdmin)<p class="mt-1 text-xs text-mono-600">Categoria do grupo: todos os participantes veem.</p>@endif</div>
                            @if ($isAdmin)
                                <div class="md:col-span-2 rounded-xl border border-mono-100 p-3" data-owner-category-field>
                                    @if ($expenseKind === 'purchase')
                                        <p class="text-sm font-medium text-mono-600">Sua categoria no Financeiro</p>
                                        <p class="mt-1 text-xs text-mono-600">Compra do bem: a sua parte vai sempre para "Aquisição de bem" (fora do resultado).</p>
                                    @else
                                        <x-banking.category-picker :categories="$ownerCategories" :selected-id="$ownerCategoryId" model="ownerCategoryId" id="division-owner-category" label="Sua categoria no Financeiro" placeholder="Sem categoria" />
                                        <p class="mt-1 text-xs text-mono-600">Só você vê. A sua parte (paga por você ou por outro sócio) entra no Financeiro nesta categoria.</p>
                                        @error('ownerCategoryId')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
                                    @endif
                                </div>
                            @endif
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Quem pagou *</label>
                                <select wire:model.live="paidBy" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach ($formMembers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                                @error('paidBy')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror</div>
                            <div><label class="mb-2 block text-sm font-medium text-mono-600">Como dividir</label>
                                <select wire:model.live="splitMode" class="h-12 w-full rounded-pill border border-mono-200 px-4 text-sm">@foreach (\App\Domains\Divisions\Models\DivisionExpense::MODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            @unless ($editingExpenseId)
                                <x-jr.input label="Parcelas" icon="view_week" type="number" min="1" max="60" name="installments" wire:model="installments" helper="1 = sem parcelamento. Cada parcela cai um mês depois." />
                            @endunless
                            @if ($linkedProperty && $isAdmin)
                                <div class="md:col-span-2 rounded-xl border border-primary-100 bg-mono-50 p-3" data-expense-kind>
                                    <label class="mb-2 block text-sm font-medium text-mono-600">Tipo (grupo ligado ao bem "{{ $linkedProperty->description }}")</label>
                                    <div class="grid grid-cols-2 gap-2 text-sm">
                                        @foreach (\App\Domains\Divisions\Models\DivisionExpense::KINDS as $k => $l)
                                            <label class="flex min-h-[44px] cursor-pointer items-center justify-center rounded-pill border {{ $expenseKind === $k ? 'border-primary-500 bg-mono-white font-semibold text-primary-500' : 'border-mono-200 bg-mono-white' }}"><input type="radio" class="sr-only" value="{{ $k }}" wire:model.live="expenseKind" data-kind="{{ $k }}">{{ $l }}</label>
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-xs text-mono-600">{{ $expenseKind === 'purchase' ? 'Parcela do bem: a sua parte entra em "Aquisição de bem", fora do resultado, e conta na sua parte do bem.' : 'Despesa comum (trator, aceiro...): só a sua parte entra no resultado; não mexe no bem.' }}</p>
                                    @if ($expenseKind === 'purchase')
                                        <label class="mt-3 mb-2 block text-sm font-medium text-mono-600">Quem pagou a mais</label>
                                        <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2" data-purchase-mode>
                                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border {{ $purchaseMode === 'advance' ? 'border-primary-500 bg-mono-white' : 'border-mono-200 bg-mono-white' }} p-3"><input type="radio" value="advance" wire:model.live="purchaseMode" data-mode="advance" class="mt-1"><span><strong>Adiantamento aos sócios</strong><span class="block text-xs text-mono-600">Segue a divisão abaixo; o que pagou a mais vira crédito a acertar.</span></span></label>
                                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border {{ $purchaseMode === 'increase' ? 'border-primary-500 bg-mono-white' : 'border-mono-200 bg-mono-white' }} p-3"><input type="radio" value="increase" wire:model.live="purchaseMode" data-mode="increase" class="mt-1"><span><strong>Aumentar minha parte</strong><span class="block text-xs text-mono-600">A parcela fica toda para quem pagou, sem dívida, e aumenta o percentual dele no bem.</span></span></label>
                                        </div>
                                    @endif
                                    @error('expenseKind')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror
                                </div>
                            @endif
                            @if ($expenseKind === 'purchase' && $purchaseMode === 'increase' && $linkedProperty && $isAdmin)
                                <p class="md:col-span-2 rounded-xl border border-mono-100 p-3 text-xs text-mono-600" data-split-increase>Sem divisão: a parcela inteira fica na parte de quem pagou.</p>
                            @else
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
                            @endif
                            @if ($canLink)
                                <div class="md:col-span-2 rounded-xl border border-mono-100 p-3" data-payment>
                                    <label class="mb-2 block text-sm font-medium text-mono-600">Como foi pago</label>
                                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" value="account" wire:model.live="paymentMethod" data-pay-method="account"> Conta</label>
                                        <label class="flex items-center gap-2"><input type="radio" value="card" wire:model.live="paymentMethod" data-pay-method="card"> Cartão</label>
                                        <label class="flex items-center gap-2"><input type="radio" value="" wire:model.live="paymentMethod" data-pay-method="none"> Não lançar no Financeiro</label>
                                    </div>
                                    @if ($paymentMethod === 'account')
                                        <select wire:model="payAccount" class="mt-3 h-12 w-full rounded-pill border border-mono-200 px-4 text-sm" data-pay-account><option value="">Escolha a conta</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                                    @elseif ($paymentMethod === 'card')
                                        <select wire:model="payCard" class="mt-3 h-12 w-full rounded-pill border border-mono-200 px-4 text-sm" data-pay-card><option value="">Escolha o cartão</option>@foreach ($cards as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                                        <p class="mt-1 text-xs text-mono-600">Entra na fatura do cartão pela data da compra.</p>
                                    @endif
                                    @if ($paymentMethod !== '')
                                        <p class="mt-2 text-xs text-mono-600">Cria a saída "Divisões: descrição" no Financeiro, vinculada a esta despesa. Editar ou excluir a despesa atualiza o lançamento.</p>
                                    @else
                                        <details class="mt-3 text-sm" data-link-existing @if ($transactionId) open @endif>
                                            <summary class="cursor-pointer text-xs text-mono-600">Vincular a lançamento existente (valor e data parecidos)</summary>
                                            <select wire:model="transactionId" class="mt-2 h-12 w-full rounded-pill border border-mono-200 px-4 text-sm" data-link-transaction>
                                                <option value="">Não vincular</option>
                                                @foreach ($linkable as $tx)<option value="{{ $tx->id }}">{{ $tx->date->format('d/m/Y') }} · {{ $tx->description }} · R$ {{ number_format((float) $tx->amount, 2, ',', '.') }} · {{ $tx->creditCard?->name ?? $tx->bankAccount?->name }}</option>@endforeach
                                            </select>
                                            @if ($linkable->isEmpty())<p class="mt-1 text-xs text-mono-600">Nenhum lançamento com valor (±10%) e data (±10 dias) parecidos.</p>@endif
                                        </details>
                                    @endif
                                    @error('paymentMethod')<p class="mt-2 text-xs text-error">{{ $message }}</p>@enderror
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

    <div x-data><template x-teleport="body"><button type="button" wire:click="newExpense" style="position:fixed;right:1.25rem;bottom:calc(1.25rem + env(safe-area-inset-bottom));z-index:60" class="lg:hidden flex items-center gap-2 rounded-pill bg-primary-500 px-5 py-3 text-sm font-semibold text-white shadow-elevated hover:bg-primary-600" data-fab-add><span class="material-icons-outlined text-[20px]">receipt_long</span> Adicionar despesa</button></template></div>
</div>

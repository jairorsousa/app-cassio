<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[Url] public string $contact = '';
    #[Url] public string $search = '';
    #[Url] public string $status = 'open';
    #[Locked] public ?int $selectedId = null;
    #[Locked] public string $createKey = '';
    #[Locked] public string $paymentKey = '';
    public bool $creating = false;
    #[Locked] public ?int $editingId = null;
    public bool $returning = false;
    #[Locked] public string $returnKey = '';
    public array $ret = [];
    public array $form = [];
    public array $payment = [];
    public $attachment = null;

    public function mount(): void
    {
        $this->resetCreate();
    }

    public function updatedContact(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    private function resetCreate(): void
    {
        $this->createKey = (string) Str::uuid();
        $this->attachment = null;
        $this->form = [
            'contact_id' => $this->contact ?: null,
            'bank_account_id' => null,
            'description' => '',
            'principal' => '',
            'date' => \App\Support\BusinessDate::today(),
            'installments' => 1,
            'first_due_date' => '',
            'interest_rate_am' => '0',
            'interest_type' => 'simple',
            'notes' => '',
        ];
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->resetCreate();
        $this->editingId = null;
        $this->creating = true;
        $this->selectedId = null;
    }

    /** Editar: mesmo formulário do novo, preenchido com o empréstimo aberto. */
    public function startEdit(): void
    {
        $loan = Loan::findOrFail($this->selectedId);
        $this->resetValidation();
        $this->resetCreate();
        $this->editingId = $loan->id;
        $this->form = [
            'contact_id' => $loan->contact_id,
            'bank_account_id' => $loan->bank_account_id,
            'description' => $loan->description,
            'principal' => number_format((float) $loan->principal, 2, '.', ''),
            'date' => $loan->date->toDateString(),
            'installments' => (int) $loan->installment_count,
            'first_due_date' => $loan->first_due_date?->toDateString() ?? '',
            'interest_rate_am' => rtrim(rtrim(number_format((float) $loan->interest_rate_am, 4, '.', ''), '0'), '.') ?: '0',
            'interest_type' => $loan->interestType(),
            'notes' => (string) $loan->notes,
        ];
        $this->creating = true;
    }

    public function cancelCreate(): void { $this->creating = false; $this->editingId = null; $this->resetValidation(); }

    /** Registrar devolução por pessoa: um recebimento abatendo um, vários ou todos os empréstimos em aberto. */
    public function startReturn(?int $contactId = null): void
    {
        $this->resetValidation();
        $contactId ??= $this->selectedId ? Loan::find($this->selectedId)?->contact_id : ($this->contact !== '' ? (int) $this->contact : null);
        $this->returnKey = (string) Str::uuid();
        $this->ret = ['contact_id' => $contactId, 'bank_account_id' => null, 'date' => \App\Support\BusinessDate::today(), 'amount' => '',
            'mode' => 'auto', 'loans' => [], 'manual' => [], 'notes' => '', 'cash' => false];
        $this->selectAllOpenLoans();
        $this->returning = true;
    }

    public function cancelReturn(): void { $this->returning = false; $this->resetValidation(); }

    public function updatedRetContactId(): void { $this->selectAllOpenLoans(); }

    public function updatedRetCash($value): void
    {
        if ($value) {
            $this->ret['bank_account_id'] = BankAccount::active()->where('type', BankAccount::TYPE_CASH)->orderBy('id')->value('id');
        }
    }

    public function selectAllOpenLoans(): void
    {
        $this->ret['loans'] = $this->openLoansFor((int) ($this->ret['contact_id'] ?? 0))->mapWithKeys(fn ($loan) => [$loan->id => true])->all();
        $this->ret['manual'] = [];
    }

    public function clearLoanSelection(): void { $this->ret['loans'] = []; }

    private function openLoansFor(int $contactId)
    {
        if (! $contactId) {
            return collect();
        }

        return Loan::with(['installments.settlements', 'settlements'])->where('contact_id', $contactId)
            ->orderBy('date')->orderBy('id')->get()->filter(fn ($loan) => $loan->remainingCents() > 0)->values();
    }

    private function selectedReturnLoanIds(): array
    {
        return array_map('intval', array_keys(array_filter((array) ($this->ret['loans'] ?? []))));
    }

    public function registerReturn(LoanService $service): void
    {
        $this->validate([
            'ret.contact_id' => 'required|integer',
            'ret.bank_account_id' => 'required|integer',
            'ret.date' => 'required|date_format:Y-m-d',
            'ret.amount' => 'required|string',
            'ret.mode' => 'required|in:auto,manual',
            'ret.notes' => 'nullable|string|max:1000',
        ], [], ['ret.contact_id' => 'pessoa', 'ret.bank_account_id' => 'conta de entrada', 'ret.date' => 'data', 'ret.amount' => 'valor recebido']);
        $ids = $this->selectedReturnLoanIds();
        $manual = $this->ret['mode'] === 'manual'
            ? collect($ids)->mapWithKeys(fn ($id) => [$id => (string) ($this->ret['manual'][$id] ?? '')])->all()
            : null;
        try {
            $service->receiveBatch((int) $this->ret['contact_id'], (string) $this->ret['amount'], $this->ret['date'], (int) $this->ret['bank_account_id'],
                $this->returnKey, $ids, $manual, $this->ret['notes'] ?: null);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addError('ret', $e->getMessage());

            return;
        }
        $this->returning = false;
        $this->selectedId = null;
        session()->flash('status', 'Devolução registrada em um único lançamento de entrada. Principal recuperado não é receita; juros recebidos entram como Juros de empréstimos.');
    }

    /** Prévia da devolução (sem gravar nada). */
    private function returnPreview(): array
    {
        $contactId = (int) ($this->ret['contact_id'] ?? 0);
        $open = $this->openLoansFor($contactId);
        $preview = ['open' => $open, 'plan' => null, 'error' => null];
        $ids = $this->selectedReturnLoanIds();
        $amount = (string) ($this->ret['amount'] ?? '');
        if (! $contactId || $ids === [] || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($this->ret['date'] ?? ''))) {
            return $preview;
        }
        $service = app(LoanService::class);
        try {
            $manual = ($this->ret['mode'] ?? 'auto') === 'manual'
                ? collect($ids)->mapWithKeys(fn ($id) => [$id => preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', (string) ($this->ret['manual'][$id] ?? '')) ? $service->cents((string) $this->ret['manual'][$id], true) : 0])->all()
                : null;
            $preview['plan'] = $service->planBatch($contactId, $service->cents($amount), $this->ret['date'], $ids, $manual);
        } catch (\DomainException|\InvalidArgumentException $e) {
            $preview['error'] = $e->getMessage();
        }

        return $preview;
    }

    private function formCents(string $key): int
    {
        $raw = $this->form[$key] ?? '';
        if ($raw === '' || $raw === null) {
            return 0;
        }
        try {
            return app(LoanService::class)->cents(\App\Support\Money::normalize((string) $raw), zero: true);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function interestPreview(): ?array
    {
        $rate = (float) (\App\Support\Money::decimal((string) ($this->form['interest_rate_am'] ?? '0'), 4) ?: 0);
        $start = $this->form['date'] ?? '';
        if ($this->formCents('principal') <= 0 || $rate <= 0 || ! $start) {
            return null;
        }

        return \App\Support\SimpleInterest::previewTotals(
            $this->formCents('principal'),
            $start,
            ($this->form['first_due_date'] ?? '') ?: null,
            $rate,
        );
    }

    public function save(LoanService $service): void
    {
        if (isset($this->form['interest_rate_am']) && is_string($this->form['interest_rate_am']) && str_contains($this->form['interest_rate_am'], ',')) {
            $this->form['interest_rate_am'] = \App\Support\Money::decimal($this->form['interest_rate_am'], 4);
        }
        $this->form['interest_type'] = \App\Support\SimpleInterest::normalizeType($this->form['interest_type'] ?? null);
        try {
            if ($this->editingId) {
                $loan = $service->update(Loan::findOrFail($this->editingId), $this->form);
                if ($this->attachment) {
                    $service->storeAttachment($loan, $this->attachment);
                }
            } else {
                $loan = $service->create($this->form + ['request_key' => $this->createKey], $this->attachment);
            }
        } catch (\DomainException|\InvalidArgumentException|\Illuminate\Validation\ValidationException $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }
            $this->addError('loan', $e->getMessage());
            return;
        }
        $this->creating = false;
        $wasEditing = $this->editingId !== null;
        $this->editingId = null;
        session()->flash('status', $wasEditing
            ? 'Empréstimo atualizado. O lançamento de saída ligado a ele foi ajustado junto.'
            : 'Empréstimo registrado. A saída saiu do caixa e virou crédito a recuperar (não é despesa).');
        $this->open($loan->id);
    }

    public function open(int $id): void
    {
        $row = Loan::with(['installments.settlements', 'settlements', 'contact', 'bankAccount'])->findOrFail($id);
        $this->selectedId = $id;
        $this->paymentKey = (string) Str::uuid();
        $balance = $row->balanceAsOf();
        $this->payment = [
            'amount' => app(LoanService::class)->money($balance['balance']),
            'date' => \App\Support\BusinessDate::today(),
            'bank_account_id' => null,
            'installment_id' => null,
            'notes' => '',
            'cash' => false,
        ];
        $this->resetValidation();
    }

    public function closeDetail(): void { $this->selectedId = null; $this->resetValidation(); }

    public function updatedPaymentCash($value): void
    {
        if ($value) {
            $cash = BankAccount::active()->where('type', BankAccount::TYPE_CASH)->orderBy('id')->first();
            $this->payment['bank_account_id'] = $cash?->id;
        }
    }

    public function settle(LoanService $service): void
    {
        $row = Loan::findOrFail($this->selectedId);
        $this->validate([
            'payment.amount' => 'required|string',
            'payment.date' => 'required|date_format:Y-m-d',
            'payment.bank_account_id' => 'required|integer',
            'payment.installment_id' => 'nullable|integer',
            'payment.notes' => 'nullable|string|max:1000',
        ]);
        try {
            $service->receive(
                $row,
                $this->payment['amount'],
                $this->payment['date'],
                (int) $this->payment['bank_account_id'],
                $this->paymentKey,
                $this->payment['installment_id'] ?: null,
                $this->payment['notes'] ?: null,
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addError('loan', $e->getMessage());
            return;
        }
        $this->open($row->id);
        session()->flash('status', 'Baixa registrada. Principal recuperado não é receita; juros recebidos entram como Juros de empréstimos.');
    }

    public function undo(string $key, LoanService $service): void
    {
        try { $service->undo(Loan::findOrFail($this->selectedId), $key); }
        catch (\DomainException $e) { $this->addError('loan', $e->getMessage()); return; }
        $this->open($this->selectedId);
        session()->flash('status', 'Devolução estornada (se era conjunta, foi desfeita em todos os empréstimos dela).');
    }

    public function detach(LoanService $service): void
    {
        try { $service->detach(Loan::findOrFail($this->selectedId)); }
        catch (\DomainException $e) { $this->addError('loan', $e->getMessage()); return; }
        $this->selectedId = null;
        session()->flash('status', 'Empréstimo excluído e lançamento de saída desfeito.');
    }

    public function with(): array
    {
        $all = Loan::with(['contact' => fn ($q) => $q->withTests(), 'installments.settlements', 'settlements', 'bankAccount'])
            ->when($this->contact, fn ($q) => $q->where('contact_id', $this->contact))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', '%'.$this->search.'%')->orWhereHas('contact', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('id')->get();
        $open = $all->filter(fn ($row) => $row->remainingCents() > 0);
        $summary = [
            'Em aberto' => $open->sum(fn ($row) => $row->remainingCents()),
            'Principal em aberto' => $open->sum(fn ($row) => $row->principalOpenCents()),
            'Quitados' => $all->filter(fn ($row) => $row->remainingCents() === 0)->count() * 100, // placeholder reused as count display? better separate
        ];
        // Fix quitados to be money-less — use separate cards in view
        $filtered = $all->filter(fn ($row) => match ($this->status) {
            'open' => $row->remainingCents() > 0,
            'paid' => $row->remainingCents() === 0,
            'overdue' => $row->statusLabel() === 'Vencido',
            default => true,
        })->values();
        $records = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($this->getPage(), 20)->values(), $filtered->count(), 20, $this->getPage(), ['path' => request()->url()]);
        $selected = $this->selectedId
            ? Loan::with(['contact', 'bankAccount', 'installments.settlements', 'settlements.transaction.bankAccount', 'disbursement'])->findOrFail($this->selectedId)
            : null;
        $selectedBalance = $selected?->balanceAsOf();
        $batchLoans = $selected
            ? \App\Domains\Banking\Models\LoanSettlement::whereIn('batch_key', $selected->settlements->pluck('batch_key')->unique())
                ->get(['batch_key', 'loan_id'])->groupBy('batch_key')->map(fn ($rows) => $rows->pluck('loan_id')->unique()->count())->all()
            : [];
        $editingLocked = $this->editingId ? \App\Domains\Banking\Models\LoanSettlement::where('loan_id', $this->editingId)->exists() : false;
        $returnPeople = $this->returning
            ? Contact::withTrashed()->whereIn('id', Loan::query()->select('contact_id'))->orderBy('name')->get()
                ->filter(fn ($c) => $this->openLoansFor($c->id)->isNotEmpty() || (int) ($this->ret['contact_id'] ?? 0) === $c->id)->values()
            : collect();

        return [
            'records' => $records,
            'openTotal' => $open->sum(fn ($row) => $row->remainingCents()),
            'principalTotal' => $open->sum(fn ($row) => $row->principalOpenCents()),
            'paidCount' => $all->filter(fn ($row) => $row->remainingCents() === 0)->count(),
            'selected' => $selected,
            'selectedBalance' => $selectedBalance,
            'batchLoans' => $batchLoans,
            'editingLocked' => $editingLocked,
            'returnPeople' => $returnPeople,
            'returnPreview' => $this->returning ? $this->returnPreview() : null,
            'interestPreview' => $this->creating ? $this->interestPreview() : null,
            'contacts' => Contact::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->where('type', '!=', 'repasse')->orderBy('name')->get(),
            'cashAccounts' => BankAccount::active()->where('type', BankAccount::TYPE_CASH)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-6">
    @if ($selected)
        <x-back-button action="closeDetail" label="Voltar à lista" />
    @else
        <x-back-button :href="route('banking.dashboard')" />
    @endif
    <x-banking.subnav active="loans" :back="false" />
    @if (session('status')) <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert> @endif
    @if (! $creating && $errors->any())
        <x-jr.alert variant="error"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-mono-900">Empréstimos concedidos</h2>
            <p class="mt-1 text-sm text-mono-600">Dinheiro emprestado a terceiros. A saída não é despesa; juros só entram no resultado quando recebidos.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-jr.button variant="standard" wire:click="startReturn" data-start-return>Registrar devolução</x-jr.button>
            <x-jr.button wire:click="startCreate">Novo empréstimo</x-jr.button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-fx.card><div class="text-xs font-medium uppercase tracking-wide text-mono-600">Saldo em aberto</div><div class="mt-2 text-2xl font-bold tabular-nums text-mono-900">R$ {{ number_format($openTotal / 100, 2, ',', '.') }}</div></x-fx.card>
        <x-fx.card><div class="text-xs font-medium uppercase tracking-wide text-mono-600">Principal em aberto</div><div class="mt-2 text-2xl font-bold tabular-nums text-mono-900">R$ {{ number_format($principalTotal / 100, 2, ',', '.') }}</div></x-fx.card>
        <x-fx.card><div class="text-xs font-medium uppercase tracking-wide text-mono-600">Quitados</div><div class="mt-2 text-2xl font-bold tabular-nums text-mono-900">{{ $paidCount }}</div></x-fx.card>
    </div>

    <x-fx.card>
        <div class="mb-4 flex flex-wrap gap-3">
            <x-jr.input label="Buscar" name="search" wire:model.live.debounce.300ms="search" placeholder="Descrição ou pessoa" />
            <div>
                <label class="mb-2 block text-sm text-mono-600">Pessoa</label>
                <select wire:model.live="contact" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                    <option value="">Todas</option>
                    @foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="mb-2 block text-sm text-mono-600">Situação</label>
                <select wire:model.live="status" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                    <option value="open">Em aberto</option>
                    <option value="paid">Quitados</option>
                    <option value="overdue">Vencidos</option>
                    <option value="">Todos</option>
                </select>
            </div>
        </div>

        @if ($selected)
            <div class="mb-6 rounded-xl border border-mono-100 bg-mono-50 p-4 scroll-mt-24" wire:key="loan-detail-{{ $selected->id }}" x-data x-init="$el.scrollIntoView({ block: 'start', behavior: 'smooth' })" data-loan-detail>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">{{ $selected->description }}</h3>
                        <a class="text-sm text-primary-500" href="{{ route('contacts.show', $selected->contact_id) }}">{{ $selected->contact->name }}</a>
                        <p class="mt-2 text-sm text-mono-600">
                            {{ $selected->statusLabel() }}
                            · Principal: R$ {{ number_format($selectedBalance['principal_open']/100, 2, ',', '.') }}
                            · Juros: R$ {{ number_format($selectedBalance['interest_open']/100, 2, ',', '.') }}
                            · Saldo: <strong class="text-mono-900">R$ {{ number_format($selectedBalance['balance']/100, 2, ',', '.') }}</strong>
                        </p>
                        <p class="mt-1 text-xs text-mono-600">Origem: {{ $selected->bankAccount->name }} · {{ $selected->date->format('d/m/Y') }} · Taxa {{ number_format((float)$selected->interest_rate_am, 2, ',', '.') }}% a.m. ({{ \App\Support\SimpleInterest::typeLabel($selected->interestType()) }})</p>
                        @if ($selected->hasAttachment())
                            <a class="mt-2 inline-block text-sm font-semibold text-primary-500" href="{{ route('banking.loans.attachment', $selected) }}">Baixar comprovante ({{ $selected->attachment_original_name }})</a>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-back-button action="closeDetail" label="Voltar" />
                        <x-jr.button variant="standard" wire:click="startEdit" data-loan-edit>
                            <span class="material-icons-outlined text-[18px]" aria-hidden="true">edit</span>Editar
                        </x-jr.button>
                        @if ($selected->settlements->isEmpty())
                            <x-jr.button variant="standard" class="!text-down" wire:click="detach" wire:confirm="Excluir este empréstimo? O lançamento de saída de R$ {{ number_format((float) $selected->principal, 2, ',', '.') }} na conta {{ $selected->bankAccount->name }} também será desfeito." data-loan-delete>
                                <span class="material-icons-outlined text-[18px]" aria-hidden="true">delete</span>Excluir
                            </x-jr.button>
                        @else
                            <x-jr.button variant="standard" disabled title="Estorne as devoluções no histórico abaixo antes de excluir" data-loan-delete-blocked>
                                <span class="material-icons-outlined text-[18px]" aria-hidden="true">delete</span>Excluir
                            </x-jr.button>
                        @endif
                    </div>
                </div>
                @if ($selected->settlements->isNotEmpty())
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900" data-loan-locked-note>Este empréstimo já tem devoluções. Para excluir, estorne antes as devoluções no histórico abaixo. Em Editar, só descrição e observações podem mudar.</p>
                @endif

                <div class="mt-4 overflow-x-auto">
                    <table class="fx-table w-full text-sm">
                        <thead><tr><th>Parcela</th><th>Valor</th><th>Principal em aberto</th><th>Vencimento</th></tr></thead>
                        <tbody>
                        @foreach ($selected->installments as $part)
                            <tr>
                                <td>{{ $part->number }}</td>
                                <td>R$ {{ number_format($part->amount, 2, ',', '.') }}</td>
                                <td>R$ {{ number_format($part->remainingPrincipalCents()/100, 2, ',', '.') }}</td>
                                <td>{{ $part->due_date?->format('d/m/Y') ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($selectedBalance['balance'] > 0)
                    <form wire:submit="settle" class="mt-5 space-y-4 border-t border-mono-100 pt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h4 class="font-semibold text-mono-900">Registrar recebimento deste empréstimo</h4>
                            <button type="button" class="text-sm font-semibold text-primary-500" wire:click="startReturn({{ $selected->contact_id }})">Devolveu vários de uma vez? Registrar devolução</button>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-jr.input label="Valor recebido (R$)" name="payment.amount" x-money inputmode="numeric" wire:model="payment.amount" />
                            <x-jr.input label="Data" name="payment.date" type="date" wire:model="payment.date" />
                            <label class="flex items-center gap-2 text-sm text-mono-600 md:col-span-2">
                                <input type="checkbox" wire:model.live="payment.cash" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500" />
                                Devolução em dinheiro (espécie) — credita a conta Dinheiro
                            </label>
                            <div>
                                <x-banking.account-picker :accounts="$accounts" :selected-id="$payment['bank_account_id'] ?? null" model="payment.bank_account_id" id="loan-payment-account" label="Conta de entrada" />
                            </div>
                            <div>
                                <label class="mb-2 block text-sm text-mono-600">Aplicar principal em</label>
                                <select wire:model="payment.installment_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                                    <option value="">Parcelas em ordem</option>
                                    @foreach ($selected->installments->filter(fn ($p) => $p->remainingPrincipalCents() > 0) as $part)
                                        <option value="{{ $part->id }}">Parcela {{ $part->number }} · R$ {{ number_format($part->remainingPrincipalCents()/100, 2, ',', '.') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-jr.input label="Observações" name="payment.notes" wire:model="payment.notes" />
                        </div>
                        <x-jr.button type="submit" wire:loading.attr="disabled">Registrar baixa</x-jr.button>
                    </form>
                @endif

                @if ($selected->settlements->isNotEmpty())
                    <div class="mt-5 border-t border-mono-100 pt-4">
                        <h4 class="mb-3 font-semibold text-mono-900">Histórico de baixas</h4>
                        <div class="overflow-x-auto">
                            <table class="fx-table w-full text-sm">
                                <thead><tr><th>Data</th><th>Total</th><th>Principal</th><th>Juros</th><th>Conta</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($selected->settlements->groupBy('batch_key') as $batch => $rows)
                                    @php $first = $rows->first(); @endphp
                                    <tr>
                                        <td>{{ $first->date->format('d/m/Y') }}</td>
                                        <td>R$ {{ number_format($rows->sum('amount'), 2, ',', '.') }}</td>
                                        <td>R$ {{ number_format($rows->sum('principal_amount'), 2, ',', '.') }}</td>
                                        <td>R$ {{ number_format($rows->sum('interest_amount'), 2, ',', '.') }}</td>
                                        <td>{{ $first->transaction?->bankAccount?->name ?: '—' }}</td>
                                        <td>
                                            @php $joint = ($batchLoans[$batch] ?? 1) > 1; @endphp
                                            @if ($joint)<span class="mr-2 rounded-pill bg-mono-100 px-2 py-0.5 text-xs text-mono-700" data-joint-return>Devolução conjunta ({{ $batchLoans[$batch] }} empréstimos)</span>@endif
                                            <button type="button" class="font-semibold text-down" wire:click="undo('{{ $batch }}')" wire:confirm="{{ $joint ? 'Estornar esta devolução conjunta? Ela será desfeita nos '.$batchLoans[$batch].' empréstimos e o lançamento de entrada será excluído.' : 'Estornar esta baixa?' }}">Estornar</button>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="fx-table w-full text-sm">
                <thead><tr><th>Data</th><th>Pessoa</th><th>Descrição</th><th>Principal</th><th>Saldo</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                @forelse ($records as $row)
                    @php $bal = $row->balanceAsOf(); @endphp
                    <tr>
                        <td>{{ $row->date->format('d/m/Y') }}</td>
                        <td>{{ $row->contact->name }}</td>
                        <td>{{ $row->description }}</td>
                        <td>R$ {{ number_format($row->principal, 2, ',', '.') }}</td>
                        <td>R$ {{ number_format($bal['balance']/100, 2, ',', '.') }}</td>
                        <td><x-jr.badge :variant="$bal['balance'] === 0 ? 'success' : ($row->statusLabel() === 'Vencido' ? 'error' : 'neutral')">{{ $row->statusLabel() }}</x-jr.badge></td>
                        <td><button type="button" class="font-semibold text-primary-500" wire:click="open({{ $row->id }})">Gerenciar</button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-mono-600">Nenhum empréstimo encontrado.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $records->links() }}</div>
    </x-fx.card>

    @if ($creating)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" x-data @keydown.escape.window="$wire.cancelCreate()">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelCreate" aria-label="Fechar modal"></button>
            <div role="dialog" aria-modal="true" class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="flex items-center gap-3 text-lg font-bold text-mono-900">
                        <span class="material-icons-outlined text-[24px] text-primary-500" aria-hidden="true">savings</span>
                        {{ $editingId ? 'Editar empréstimo' : 'Novo empréstimo concedido' }}
                    </h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 hover:bg-mono-100" wire:click="cancelCreate" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]" aria-hidden="true">close</span>
                    </button>
                </div>
                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        @if ($errors->any())
                            <div class="mb-5" role="alert"><x-jr.alert variant="error" :dismissible="false"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert></div>
                        @endif
                        @if ($editingLocked)
                            <p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-edit-locked>Este empréstimo já tem devoluções registradas. Por isso só a descrição e as observações podem ser alteradas. Para mudar pessoa, conta, valor, data, parcelas, vencimento ou juros, estorne antes as devoluções no histórico.</p>
                        @endif
                        <fieldset @disabled($editingLocked) class="grid gap-4 md:grid-cols-2 {{ $editingLocked ? 'opacity-60' : '' }}">
                            <x-banking.contact-picker :contacts="$contacts" :selected-id="$form['contact_id'] ?? null" model="form.contact_id" id="loan-contact" />
                            <x-banking.account-picker :accounts="$accounts" :selected-id="$form['bank_account_id'] ?? null" model="form.bank_account_id" id="loan-account" label="Conta de origem (saída)" />
                            <x-jr.input label="Valor emprestado (R$)" name="form.principal" x-money inputmode="numeric" wire:model.live.debounce.400ms="form.principal" />
                            <x-jr.input label="Data" name="form.date" type="date" wire:model.live="form.date" />
                            <x-jr.input label="Número de parcelas" name="form.installments" type="number" min="1" max="120" wire:model.live="form.installments" />
                            @if ((int) $form['installments'] > 1)
                                <x-jr.input label="Primeiro vencimento" name="form.first_due_date" type="date" wire:model.live="form.first_due_date" />
                            @else
                                <x-jr.input label="Vencimento (opcional)" name="form.first_due_date" type="date" wire:model.live="form.first_due_date" />
                            @endif
                            <div class="space-y-3" data-loan-interest>
                                <x-jr.input label="Taxa de juros (% a.m.)" name="form.interest_rate_am" x-decimal="4" wire:model.live.debounce.400ms="form.interest_rate_am" helper="Padrão 0. Juros só viram receita quando recebidos." />
                                <x-banking.interest-type model="form.interest_type" id="loan-interest-type" :preview="$interestPreview" />
                            </div>
                        </fieldset>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <x-jr.input label="Descrição" name="form.description" wire:model="form.description" />
                            <x-jr.input label="Observações" name="form.notes" wire:model="form.notes" />
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm text-mono-600">Comprovante (PDF, JPG ou PNG, opcional)</label>
                                <input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="block w-full text-sm text-mono-600" />
                                <div wire:loading wire:target="attachment" class="mt-1 text-xs text-mono-600">Enviando…</div>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-mono-600">{{ $editingId ? 'Ao salvar, o lançamento de saída ligado a este empréstimo é ajustado junto (conta, valor, data e descrição).' : 'Ao salvar, o valor sai da conta de origem como conversão caixa→crédito a recuperar. Não conta como despesa no resultado.' }}</p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <x-jr.button variant="standard" wire:click="cancelCreate">Cancelar</x-jr.button>
                        <x-jr.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-jr.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($returning)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" x-data @keydown.escape.window="$wire.cancelReturn()" data-return-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelReturn" aria-label="Fechar modal"></button>
            <div role="dialog" aria-modal="true" aria-labelledby="return-title" class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 id="return-title" class="flex items-center gap-3 text-lg font-bold text-mono-900">
                        <span class="material-icons-outlined text-[24px] text-primary-500" aria-hidden="true">payments</span>
                        Registrar devolução
                    </h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 hover:bg-mono-100" wire:click="cancelReturn" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]" aria-hidden="true">close</span>
                    </button>
                </div>
                <form wire:submit="registerReturn" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <p class="mb-4 text-sm text-mono-600">Um pagamento da pessoa abatendo um, vários ou todos os empréstimos em aberto. Gera um único lançamento de entrada na conta.</p>
                        @if ($errors->any())
                            <div class="mb-5" role="alert"><x-jr.alert variant="error" :dismissible="false"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert></div>
                        @endif
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="return-contact" class="mb-2 block text-sm text-mono-600">Pessoa</label>
                                <select id="return-contact" wire:model.live="ret.contact_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500" data-return-contact>
                                    <option value="">Selecione</option>
                                    @foreach ($returnPeople as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <x-banking.account-picker :accounts="$accounts" :selected-id="$ret['bank_account_id'] ?? null" model="ret.bank_account_id" id="return-account" label="Conta de entrada" />
                                <label class="mt-2 flex items-center gap-2 text-xs text-mono-600"><input type="checkbox" wire:model.live="ret.cash" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500" /> Recebi em dinheiro (espécie)</label>
                            </div>
                            <x-jr.input label="Data do recebimento" name="ret.date" type="date" wire:model.live="ret.date" />
                            <x-jr.input label="Valor recebido (R$)" name="ret.amount" x-money inputmode="numeric" wire:model.live.debounce.500ms="ret.amount" data-return-amount />
                        </div>

                        <div class="mt-5">
                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="font-semibold text-mono-900">Empréstimos em aberto</h4>
                                <div class="flex gap-3 text-sm">
                                    <button type="button" class="font-semibold text-primary-500" wire:click="selectAllOpenLoans">Marcar todos</button>
                                    <button type="button" class="font-semibold text-mono-600" wire:click="clearLoanSelection">Desmarcar</button>
                                </div>
                            </div>
                            <div class="mb-3 flex flex-col gap-2 text-sm text-mono-700 sm:flex-row sm:gap-6">
                                <label class="flex items-center gap-2"><input type="radio" value="auto" wire:model.live="ret.mode" class="text-primary-500 focus:ring-primary-500" data-return-mode="auto" /> Distribuir automaticamente (do mais antigo para o mais novo)</label>
                                <label class="flex items-center gap-2"><input type="radio" value="manual" wire:model.live="ret.mode" class="text-primary-500 focus:ring-primary-500" data-return-mode="manual" /> Informar o valor de cada um</label>
                            </div>
                            @php $plan = $returnPreview['plan'] ?? null; $planRows = collect($plan['rows'] ?? [])->keyBy(fn ($r) => $r['loan']->id); @endphp
                            @if ($returnPreview['open']->isEmpty())
                                <p class="rounded-lg bg-mono-50 px-3 py-4 text-center text-sm text-mono-600">{{ ($ret['contact_id'] ?? null) ? 'Esta pessoa não tem empréstimos em aberto.' : 'Escolha a pessoa para ver os empréstimos em aberto.' }}</p>
                            @else
                                <div class="space-y-2" data-return-loans>
                                    @foreach ($returnPreview['open'] as $loan)
                                        @php $row = $planRows->get($loan->id); $checked = ! empty($ret['loans'][$loan->id]); @endphp
                                        <div class="rounded-xl border {{ $checked ? 'border-primary-300 bg-primary-50/40' : 'border-mono-100' }} p-3" wire:key="ret-loan-{{ $loan->id }}" data-return-loan="{{ $loan->id }}">
                                            <div class="flex items-start gap-3">
                                                <input type="checkbox" id="ret-loan-{{ $loan->id }}" wire:model.live="ret.loans.{{ $loan->id }}" class="mt-1 h-5 w-5 rounded border-mono-200 text-primary-500 focus:ring-primary-500" />
                                                <label for="ret-loan-{{ $loan->id }}" class="min-w-0 flex-1 cursor-pointer">
                                                    <span class="block font-semibold text-mono-900">{{ $loan->description }} <span class="font-normal text-mono-600">· {{ $loan->date->format('d/m/Y') }}</span></span>
                                                    <span class="block text-xs text-mono-600">Saldo hoje: R$ {{ number_format($loan->remainingCents() / 100, 2, ',', '.') }}@if ($loan->installments->count() > 1) · {{ $loan->installments->filter(fn ($p) => $p->remainingPrincipalCents() > 0)->count() }} parcela(s) em aberto @endif</span>
                                                </label>
                                            </div>
                                            @if ($checked && ($ret['mode'] ?? 'auto') === 'manual')
                                                <div class="mt-2 sm:ml-8 sm:max-w-xs">
                                                    <x-jr.input label="Abater deste (R$)" name="ret.manual.{{ $loan->id }}" x-money inputmode="numeric" wire:model.live.debounce.500ms="ret.manual.{{ $loan->id }}" />
                                                </div>
                                            @endif
                                            @if ($checked && $row)
                                                <div class="mt-2 grid grid-cols-3 gap-2 rounded-lg bg-mono-white px-3 py-2 text-xs sm:ml-8" data-return-row>
                                                    <div><span class="block text-mono-500">Saldo na data</span><span class="font-semibold tabular-nums text-mono-900">R$ {{ number_format($row['open'] / 100, 2, ',', '.') }}</span></div>
                                                    <div><span class="block text-mono-500">Abatido</span><span class="font-semibold tabular-nums text-up">R$ {{ number_format($row['applied'] / 100, 2, ',', '.') }}</span></div>
                                                    <div><span class="block text-mono-500">Fica em aberto</span><span class="font-semibold tabular-nums {{ $row['remaining'] > 0 ? 'text-down' : 'text-mono-900' }}" data-return-remaining>R$ {{ number_format($row['remaining'] / 100, 2, ',', '.') }}</span></div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if ($returnPreview['error'])
                                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-down" data-return-error>{{ $returnPreview['error'] }}</p>
                                @elseif ($plan)
                                    <div class="mt-3 rounded-lg bg-mono-50 px-3 py-2 text-sm text-mono-700" data-return-summary>
                                        Recebido R$ {{ number_format($plan['total'] / 100, 2, ',', '.') }} · abatido R$ {{ number_format($plan['applied'] / 100, 2, ',', '.') }}
                                        @if ($plan['left'] > 0)<span class="block font-semibold text-down">Sobram R$ {{ number_format($plan['left'] / 100, 2, ',', '.') }} sem empréstimo: marque mais empréstimos ou ajuste os valores.</span>@endif
                                        @if ($plan['left'] < 0)<span class="block font-semibold text-down">A soma passa do valor recebido em R$ {{ number_format(-$plan['left'] / 100, 2, ',', '.') }}.</span>@endif
                                    </div>
                                @endif
                            @endif
                        </div>
                        <div class="mt-4"><x-jr.input label="Observações" name="ret.notes" wire:model="ret.notes" /></div>
                        <p class="mt-3 text-xs text-mono-600">Principal recuperado não é receita. Se houver juros, só a parte de juros entra no resultado.</p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <x-jr.button variant="standard" wire:click="cancelReturn">Cancelar</x-jr.button>
                        <x-jr.button type="submit" wire:loading.attr="disabled" wire:target="registerReturn" data-return-submit>Registrar devolução</x-jr.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

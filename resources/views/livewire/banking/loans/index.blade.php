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
            'notes' => '',
        ];
    }

    public function startCreate(): void
    {
        $this->resetValidation();
        $this->resetCreate();
        $this->creating = true;
        $this->selectedId = null;
    }

    public function cancelCreate(): void { $this->creating = false; $this->resetValidation(); }

    public function save(LoanService $service): void
    {
        try {
            $loan = $service->create($this->form + ['request_key' => $this->createKey], $this->attachment);
        } catch (\DomainException|\InvalidArgumentException|\Illuminate\Validation\ValidationException $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }
            $this->addError('loan', $e->getMessage());
            return;
        }
        $this->creating = false;
        session()->flash('status', 'Empréstimo registrado. A saída saiu do caixa e virou crédito a recuperar (não é despesa).');
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
        session()->flash('status', 'Baixa estornada.');
    }

    public function detach(LoanService $service): void
    {
        try { $service->detach(Loan::findOrFail($this->selectedId)); }
        catch (\DomainException $e) { $this->addError('loan', $e->getMessage()); return; }
        $this->selectedId = null;
        session()->flash('status', 'Empréstimo removido e saída gerada estornada.');
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

        return [
            'records' => $records,
            'openTotal' => $open->sum(fn ($row) => $row->remainingCents()),
            'principalTotal' => $open->sum(fn ($row) => $row->principalOpenCents()),
            'paidCount' => $all->filter(fn ($row) => $row->remainingCents() === 0)->count(),
            'selected' => $selected,
            'selectedBalance' => $selectedBalance,
            'contacts' => Contact::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->where('type', '!=', 'repasse')->orderBy('name')->get(),
            'cashAccounts' => BankAccount::active()->where('type', BankAccount::TYPE_CASH)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-6">
    <x-banking.subnav active="loans" />
    @if (session('status')) <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert> @endif
    @if (! $creating && $errors->any())
        <x-jr.alert variant="error"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-mono-900">Empréstimos concedidos</h2>
            <p class="mt-1 text-sm text-mono-600">Dinheiro emprestado a terceiros. A saída não é despesa; juros só entram no resultado quando recebidos.</p>
        </div>
        <x-jr.button wire:click="startCreate">Novo empréstimo</x-jr.button>
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
            <div class="mb-6 rounded-xl border border-mono-100 bg-mono-50 p-4">
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
                        <p class="mt-1 text-xs text-mono-600">Origem: {{ $selected->bankAccount->name }} · {{ $selected->date->format('d/m/Y') }} · Taxa {{ number_format((float)$selected->interest_rate_am, 2, ',', '.') }}% a.m.</p>
                        @if ($selected->hasAttachment())
                            <a class="mt-2 inline-block text-sm font-semibold text-primary-500" href="{{ route('banking.loans.attachment', $selected) }}">Baixar comprovante ({{ $selected->attachment_original_name }})</a>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-jr.button variant="standard" wire:click="closeDetail">Fechar</x-jr.button>
                        @if ($selected->settlements->isEmpty())
                            <x-jr.button variant="standard" wire:click="detach" wire:confirm="Remover este empréstimo e estornar a saída?">Remover</x-jr.button>
                        @endif
                    </div>
                </div>

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
                        <h4 class="font-semibold text-mono-900">Registrar recebimento</h4>
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
                                        <td><button type="button" class="font-semibold text-down" wire:click="undo('{{ $batch }}')" wire:confirm="Estornar esta baixa?">Estornar</button></td>
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
                        Novo empréstimo concedido
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
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-banking.contact-picker :contacts="$contacts" :selected-id="$form['contact_id'] ?? null" model="form.contact_id" id="loan-contact" />
                            <x-banking.account-picker :accounts="$accounts" :selected-id="$form['bank_account_id'] ?? null" model="form.bank_account_id" id="loan-account" label="Conta de origem (saída)" />
                            <x-jr.input label="Descrição" name="form.description" wire:model="form.description" />
                            <x-jr.input label="Valor emprestado (R$)" name="form.principal" x-money inputmode="numeric" wire:model="form.principal" />
                            <x-jr.input label="Data" name="form.date" type="date" wire:model="form.date" />
                            <x-jr.input label="Número de parcelas" name="form.installments" type="number" min="1" max="120" wire:model.live="form.installments" />
                            @if ((int) $form['installments'] > 1)
                                <x-jr.input label="Primeiro vencimento" name="form.first_due_date" type="date" wire:model="form.first_due_date" />
                            @else
                                <x-jr.input label="Vencimento (opcional)" name="form.first_due_date" type="date" wire:model="form.first_due_date" />
                            @endif
                            <x-jr.input label="Taxa de juros simples (% a.m.)" name="form.interest_rate_am" wire:model="form.interest_rate_am" helper="Padrão 0. Juros só viram receita quando recebidos." />
                            <x-jr.input label="Observações" name="form.notes" wire:model="form.notes" />
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm text-mono-600">Comprovante (PDF, JPG ou PNG, opcional)</label>
                                <input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="block w-full text-sm text-mono-600" />
                                <div wire:loading wire:target="attachment" class="mt-1 text-xs text-mono-600">Enviando…</div>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-mono-600">Ao salvar, o valor sai da conta de origem como conversão caixa→crédito a recuperar. Não conta como despesa no resultado.</p>
                    </div>
                    <div class="flex shrink-0 justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <x-jr.button variant="standard" wire:click="cancelCreate">Cancelar</x-jr.button>
                        <x-jr.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-jr.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

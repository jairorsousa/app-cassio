<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoicePurchaseService;
use App\Domains\Banking\Services\InvoiceService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public CreditCard $card;

    public ?int $payingInvoiceId = null;
    public ?int $payment_account_id = null;
    public string $payment_amount = '';
    public string $payment_date = '';
    public string $payment_notes = '';

    public ?int $expandedInvoiceId = null;

    /** @var list<int|string> */
    public array $selectedIds = [];

    public ?int $editingPurchaseId = null;
    public string $edit_date = '';
    public string $edit_description = '';
    public string $edit_amount = '';
    public mixed $edit_category_id = null;
    public string $edit_invoice_month = '';

    public string $move_to_month = '';

    public ?int $editingInvoiceId = null;
    public string $invoice_closing_date = '';
    public string $invoice_due_date = '';

    public bool $showUndo = false;
    public ?string $undoKey = null;

    /** Filtros da fatura: por cartão (principal, virtual ou adicional) e por titular. */
    public string $cardFilter = '';
    public string $holderFilter = '';

    public function mount(CreditCard $card)
    {
        if ($card->parent_card_id && $card->parent) {
            return $this->redirectRoute('banking.cards.invoices', ['card' => $card->parent_card_id, 'cardFilter' => $card->id]);
        }
        $this->cardFilter = (string) request()->query('cardFilter', '');
        $this->card = $card;
        $this->payment_account_id = $card->default_payment_account_id;
        $this->payment_date = \App\Support\BusinessDate::today();
    }

    public function toggle(int $invoiceId): void
    {
        $this->expandedInvoiceId = $this->expandedInvoiceId === $invoiceId ? null : $invoiceId;
    }

    public function startPayment(int $invoiceId): void
    {
        $invoice = $this->card->invoices()->findOrFail($invoiceId);
        if (! $invoice->isPayable()) {
            session()->flash('error', 'Esta fatura já está quitada.');

            return;
        }
        $this->payingInvoiceId = $invoice->id;
        $this->payment_amount = number_format($invoice->remainingAmount(), 2, '.', '');
        $this->payment_account_id = $this->payment_account_id ?: $this->card->default_payment_account_id;
        $this->payment_date = \App\Support\BusinessDate::today();
        $this->payment_notes = '';
        $this->resetValidation();
    }

    public function cancelPayment(): void
    {
        $this->payingInvoiceId = null;
        $this->resetValidation();
    }

    public function pay(InvoicePaymentService $service): void
    {
        $this->payment_amount = \App\Support\Money::normalize($this->payment_amount);
        $data = $this->validate([
            'payment_account_id' => 'required|exists:bank_accounts,id',
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date|before_or_equal:'.\App\Support\BusinessDate::today(),
            'payment_notes' => 'nullable|string|max:500',
        ], [
            'payment_account_id.required' => 'Escolha a conta de onde saiu o pagamento.',
            'payment_amount.min' => 'Informe um valor maior que zero.',
            'payment_date.before_or_equal' => 'A data do pagamento não pode ser futura.',
        ]);

        $invoice = $this->card->invoices()->findOrFail($this->payingInvoiceId);
        $account = BankAccount::findOrFail($data['payment_account_id']);

        try {
            $service->pay($invoice, $account, (float) $data['payment_amount'], $data['payment_date'], $data['payment_notes'] ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->addError('payment_amount', $e->getMessage());

            return;
        }

        $fresh = $invoice->fresh();
        $this->payingInvoiceId = null;
        $this->payment_amount = '';
        session()->flash('status', $fresh->displayStatus() === 'paid'
            ? 'Pagamento registrado. Fatura '.$fresh->reference_month.' quitada.'
            : 'Pagamento parcial registrado. Restam R$ '.number_format($fresh->remainingAmount(), 2, ',', '.').'.');
    }

    public function close(int $invoiceId, InvoiceService $service): void
    {
        $invoice = $this->card->invoices()->findOrFail($invoiceId);
        $service->closeInvoice($invoice);
        session()->flash('status', 'Fatura fechada.');
    }


    public function startEditPurchase(int $id): void
    {
        $t = $this->cardPurchase($id);
        $this->editingPurchaseId = $t->id;
        $this->expandedInvoiceId = $t->credit_card_invoice_id;
        $this->edit_date = $t->date->format('Y-m-d');
        $this->edit_description = $t->description;
        $this->edit_amount = number_format((float) $t->amount, 2, '.', '');
        $this->edit_category_id = $t->category_id;
        $this->edit_invoice_month = $t->invoice?->reference_month ?? '';
        $this->resetValidation();
    }

    public function cancelEditPurchase(): void
    {
        $this->editingPurchaseId = null;
        $this->resetValidation();
    }

    public function savePurchase(InvoicePurchaseService $service, InvoiceService $invoices): void
    {
        $this->edit_amount = \App\Support\Money::normalize($this->edit_amount);
        $data = $this->validate([
            'edit_date' => 'required|date',
            'edit_description' => 'required|string|max:255',
            'edit_amount' => 'required|numeric|min:0.01',
            'edit_category_id' => 'nullable|exists:categories,id',
            'edit_invoice_month' => 'required|date_format:Y-m',
        ], [], [
            'edit_date' => 'data', 'edit_description' => 'descrição', 'edit_amount' => 'valor',
            'edit_invoice_month' => 'fatura',
        ]);
        try {
            $service->updatePurchase($this->cardPurchase($this->editingPurchaseId), $this->card, [
                'date' => $data['edit_date'],
                'description' => $data['edit_description'],
                'amount' => $data['edit_amount'],
                'category_id' => $data['edit_category_id'] ?: null,
                'invoice_month' => $data['edit_invoice_month'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('edit_invoice_month', $e->getMessage());

            return;
        }
        $this->editingPurchaseId = null;
        session()->flash('status', 'Compra atualizada. Os totais da fatura foram recalculados.');
    }

    public function deletePurchase(int $id, InvoicePurchaseService $service): void
    {
        try {
            $service->deletePurchases($this->card, [$id]);
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        $this->selectedIds = array_values(array_filter($this->selectedIds, fn ($x) => (int) $x !== $id));
        if ($this->editingPurchaseId === $id) {
            $this->editingPurchaseId = null;
        }
        session()->flash('status', 'Compra excluída.');
    }

    public function moveSelected(InvoicePurchaseService $service): void
    {
        $this->validate([
            'move_to_month' => 'required|date_format:Y-m',
            'selectedIds' => 'required|array|min:1',
        ], [], ['move_to_month' => 'fatura de destino', 'selectedIds' => 'compras']);
        try {
            $n = $service->movePurchases($this->card, array_map('intval', $this->selectedIds), $this->move_to_month);
        } catch (\InvalidArgumentException $e) {
            $this->addError('move_to_month', $e->getMessage());

            return;
        }
        $this->selectedIds = [];
        session()->flash('status', $n === 1 ? '1 compra movida.' : $n.' compras movidas. Os totais foram recalculados.');
    }

    public function deleteSelected(InvoicePurchaseService $service): void
    {
        if ($this->selectedIds === []) {
            session()->flash('error', 'Marque ao menos uma compra.');

            return;
        }
        try {
            $n = $service->deletePurchases($this->card, array_map('intval', $this->selectedIds));
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        $this->selectedIds = [];
        session()->flash('status', $n === 1 ? '1 compra excluída.' : $n.' compras excluídas.');
    }

    public function startEditInvoice(int $invoiceId): void
    {
        $invoice = $this->card->invoices()->findOrFail($invoiceId);
        $this->editingInvoiceId = $invoice->id;
        $this->invoice_closing_date = $invoice->closing_date->format('Y-m-d');
        $this->invoice_due_date = $invoice->due_date->format('Y-m-d');
        $this->resetValidation();
    }

    public function cancelEditInvoice(): void
    {
        $this->editingInvoiceId = null;
    }

    public function saveInvoice(InvoiceService $service): void
    {
        $data = $this->validate([
            'invoice_closing_date' => 'required|date',
            'invoice_due_date' => 'required|date',
        ], [], ['invoice_closing_date' => 'fechamento', 'invoice_due_date' => 'vencimento']);
        try {
            $service->updateDates($this->card->invoices()->findOrFail($this->editingInvoiceId), $data['invoice_closing_date'], $data['invoice_due_date']);
        } catch (\InvalidArgumentException $e) {
            $this->addError('invoice_due_date', $e->getMessage());

            return;
        }
        $this->editingInvoiceId = null;
        session()->flash('status', 'Datas da fatura atualizadas.');
    }

    public function reopen(int $invoiceId, InvoiceService $service): void
    {
        $service->reopenInvoice($this->card->invoices()->findOrFail($invoiceId));
        session()->flash('status', 'Fatura reaberta. Você pode editar as compras e as datas.');
    }

    public function deleteInvoice(int $invoiceId, InvoiceService $service): void
    {
        try {
            $service->deleteIfEmpty($this->card->invoices()->findOrFail($invoiceId));
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        if ($this->expandedInvoiceId === $invoiceId) {
            $this->expandedInvoiceId = null;
        }
        session()->flash('status', 'Fatura excluída.');
    }

    public function toggleSelectInvoice(int $invoiceId): void
    {
        $ids = $this->card->invoices()->findOrFail($invoiceId)->transactions()->where('type', 'expense')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $selected = collect($this->selectedIds)->map(fn ($id) => (string) $id);
        $allOn = collect($ids)->every(fn ($id) => $selected->contains($id));
        if ($allOn) {
            $this->selectedIds = $selected->reject(fn ($id) => in_array($id, $ids, true))->values()->all();
        } else {
            $this->selectedIds = $selected->merge($ids)->unique()->values()->all();
        }
    }

    public function openUndo(): void
    {
        $this->showUndo = true;
        $this->undoKey = null;
        $this->resetValidation();
    }

    public function cancelUndo(): void
    {
        $this->showUndo = false;
        $this->undoKey = null;
    }

    public function confirmUndo(InvoicePurchaseService $service): void
    {
        $this->validate(['undoKey' => 'required|string'], [], ['undoKey' => 'importação']);
        try {
            $n = $service->undoImport($this->card, $this->undoKey);
        } catch (\InvalidArgumentException $e) {
            $this->addError('undoKey', $e->getMessage());

            return;
        }
        $this->showUndo = false;
        $this->undoKey = null;
        $this->selectedIds = [];
        session()->flash('status', $n === 1 ? '1 compra da importação foi removida.' : $n.' compras da importação foram removidas.');
    }

    private function cardPurchase(int $id): \App\Domains\Banking\Models\Transaction
    {
        $t = \App\Domains\Banking\Models\Transaction::query()->findOrFail($id);
        if ((int) $t->credit_card_id !== (int) $this->card->id && (int) $t->additional_card_id !== (int) $this->card->id) {
            $ids = $this->card->additionals()->pluck('id')->all();
            if (! in_array((int) $t->credit_card_id, array_map('intval', $ids), true) && ! in_array((int) $t->additional_card_id, array_map('intval', $ids), true)) {
                abort(404);
            }
        }

        return $t;
    }

    public function with(): array
    {
        $invoices = $this->card->invoices()
            ->with(['transactions' => fn ($q) => $q->with(['category', 'bankAccount'])->orderBy('date')->orderBy('id')])
            ->orderByDesc('reference_month')->get();

        $linked = $this->card->additionals()->with(['holder', 'parent'])->orderBy('name')->get();
        $cardsById = $linked->keyBy('id')->put($this->card->id, $this->card);
        $match = function ($t) use ($cardsById) {
            $owner = $cardsById[$t->additional_card_id ?? $this->card->id] ?? $this->card;
            if ($this->cardFilter !== '' && (string) $owner->id !== $this->cardFilter) {
                return false;
            }

            return $this->holderFilter === '' || $owner->holderKey() === $this->holderFilter;
        };
        $holderOpts = $cardsById->mapWithKeys(fn ($c) => [$c->holderKey() => $c->holderLabel()])->sort()->all();

        return [
            'linked' => $linked,
            'cardsById' => $cardsById,
            'match' => $match,
            'holderOpts' => $holderOpts,
            'invoices' => $invoices,
            'paying' => $this->payingInvoiceId ? $invoices->firstWhere('id', $this->payingInvoiceId) : null,
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'categories' => Category::active()->where('type', 'expense')->orderBy('name')->get(['id', 'name']),
            'imports' => $this->showUndo ? app(InvoicePurchaseService::class)->listImports($this->card) : [],
            'suggestedEditMonth' => ($this->editingPurchaseId && $this->edit_date)
                ? app(InvoiceService::class)->referenceMonthForPurchase($this->card, \Illuminate\Support\Carbon::parse($this->edit_date))
                : null,
            'summary' => [
                'due' => $invoices->filter(fn ($i) => $i->status !== 'open')->sum(fn ($i) => $i->remainingAmount()),
                'overdue' => $invoices->filter(fn ($i) => $i->isOverdue())->sum(fn ($i) => $i->remainingAmount()),
                'open' => $invoices->where('status', 'open')->sum(fn ($i) => $i->remainingAmount()),
            ],
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

@php
    $badge = ['open' => 'bg-blue-50 text-blue-700', 'closed' => 'bg-amber-50 text-amber-700', 'partially_paid' => 'bg-orange-50 text-orange-700', 'paid' => 'bg-green-50 text-green-700', 'overdue' => 'bg-red-50 text-red-700'];
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
@endphp

<div class="flex flex-col gap-md">
    <x-banking.subnav :back="route('banking.cards.index')" />
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">{{ $card->name }}</h3>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-mono-600">
                    <span>Titular: <strong class="{{ $card->hasHolder() ? 'text-mono-900' : 'text-amber-700' }}">{{ $card->holderLabel() }}</strong></span>
                    <span>Fecha dia {{ $card->closing_day }} · vence dia {{ $card->due_day }}</span>
                    @if ($card->defaultPaymentAccount)<span>Conta padrão: {{ $card->defaultPaymentAccount->name }}</span>@endif
                    @if ($card->last_four)<span>Final {{ $card->last_four }} · principal</span>@endif
                </div>
                @if ($linked->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-1 text-xxs" data-linked-cards>
                        @foreach ($linked as $lc)<span class="rounded-pill bg-mono-50 px-2 py-0.5 text-mono-700">{{ $lc->typeLabel() }} {{ $lc->last_four ? 'final '.$lc->last_four : $lc->name }} · {{ $lc->holderLabel() }}</span>@endforeach
                    </div>
                @endif
                @if ($linked->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2" data-invoice-filters>
                        <select wire:model.live="cardFilter" class="h-9 rounded-xl border border-mono-200 px-2 text-xs" aria-label="Filtrar por cartão">
                            <option value="">Todos os cartões</option>
                            @foreach ($cardsById as $fc)<option value="{{ $fc->id }}">{{ $fc->typeLabel() }} · {{ $fc->last_four ? 'final '.$fc->last_four : $fc->name }}</option>@endforeach
                        </select>
                        <select wire:model.live="holderFilter" class="h-9 rounded-xl border border-mono-200 px-2 text-xs" aria-label="Filtrar por titular">
                            <option value="">Todos os titulares</option>
                            @foreach ($holderOpts as $hk => $hl)<option value="{{ $hk }}">{{ $hl }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
            <div class="flex flex-col items-stretch gap-2 sm:items-end">
                <div class="grid grid-cols-3 gap-2 text-center text-xs" data-testid="invoice-summary">
                    <div class="rounded-xl bg-mono-50 px-3 py-2"><div class="text-mono-600">A pagar (fechadas)</div><div class="font-semibold text-mono-900">{{ $brl($summary['due']) }}</div></div>
                    <div class="rounded-xl bg-red-50 px-3 py-2"><div class="text-red-700">Vencido</div><div class="font-semibold text-red-700">{{ $brl($summary['overdue']) }}</div></div>
                    <div class="rounded-xl bg-blue-50 px-3 py-2"><div class="text-blue-700">Em aberto</div><div class="font-semibold text-blue-700">{{ $brl($summary['open']) }}</div></div>
                </div>
                <button type="button" class="h-11 rounded-pill border border-mono-200 px-4 text-sm font-semibold" wire:click="openUndo" data-undo-import>Desfazer importação</button>
            </div>
        </div>
    </x-fx.card>

    <x-fx.card>
        @if ($invoices->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma fatura gerada ainda. Lance uma compra no cartão para abrir uma fatura.</div>
        @else
            <div class="flex flex-col divide-y divide-mono-100">
                @foreach ($invoices as $inv)
                    @php $st = $inv->displayStatus(); $allPurchases = $inv->transactions->where('type', 'expense'); $purchases = $allPurchases->filter($match); $filtered = $cardFilter !== '' || $holderFilter !== ''; $payments = $inv->transactions->where('type', 'invoice_payment'); @endphp
                    <div class="py-3" wire:key="inv-{{ $inv->id }}">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div class="flex items-center gap-3">
                                <button type="button" class="text-mono-400 hover:text-mono-700" wire:click="toggle({{ $inv->id }})" aria-label="Ver compras e pagamentos">
                                    <span class="material-icons-outlined text-[20px]">{{ $expandedInvoiceId === $inv->id ? 'expand_less' : 'expand_more' }}</span>
                                </button>
                                <div>
                                    <div class="font-semibold text-mono-900">Fatura {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $inv->reference_month)->translatedFormat('M/Y') }}</div>
                                    <div class="text-xxs text-mono-600">Fecha {{ $inv->closing_date->format('d/m/Y') }} · vence {{ $inv->due_date->format('d/m/Y') }}</div>
                                </div>
                                <span class="rounded-pill px-2 py-0.5 text-xxs font-semibold {{ $badge[$st] }}">{{ \App\Domains\Banking\Models\CreditCardInvoice::STATUS_LABELS[$st] }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-3 text-right text-xs md:w-[360px]">
                                <div><div class="text-mono-500">Total</div><div class="font-medium">{{ $brl($inv->total) }}</div></div>
                                <div><div class="text-mono-500">Pago</div><div class="font-medium text-green-700">{{ $brl($inv->paid_amount) }}</div></div>
                                <div><div class="text-mono-500">Restante</div><div class="font-semibold">{{ $brl($inv->remainingAmount()) }}</div></div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 md:justify-end">
                                @if ($inv->isPayable())
                                    <button class="fx-btn fx-btn--primary fx-btn--sm min-h-11" wire:click="startPayment({{ $inv->id }})">{{ $inv->status === 'open' ? 'Antecipar' : 'Pagar' }}</button>
                                @endif
                                <div class="relative" x-data="{ open: false }" @keydown.escape.window="open=false">
                                    <button type="button" class="fx-btn fx-btn--text fx-btn--sm min-h-11 px-3" @click="open=!open" @click.outside="open=false" data-invoice-manage="{{ $inv->id }}" aria-haspopup="menu" :aria-expanded="open">
                                        Gerenciar <span class="material-icons-outlined text-[18px]">expand_more</span>
                                    </button>
                                    <div x-show="open" x-cloak x-transition class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-mono-100 bg-mono-white py-1 shadow-elevated" role="menu">
                                        @if ($inv->status === 'open')
                                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-mono-50" wire:click="close({{ $inv->id }})" wire:confirm="Fechar a fatura {{ $inv->reference_month }}? Novas compras irão para a próxima." @click="open=false">Fechar fatura</button>
                                        @elseif ($inv->status !== 'paid')
                                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-mono-50" wire:click="reopen({{ $inv->id }})" data-invoice-reopen="{{ $inv->id }}" @click="open=false">Reabrir fatura</button>
                                        @else
                                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-mono-50" wire:click="reopen({{ $inv->id }})" data-invoice-reopen="{{ $inv->id }}" @click="open=false">Reabrir fatura</button>
                                        @endif
                                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-mono-50" wire:click="startEditInvoice({{ $inv->id }})" data-invoice-edit="{{ $inv->id }}" @click="open=false">Editar vencimento e fechamento</button>
                                        @php
                                            $hasPay = $inv->transactions->where('type', 'invoice_payment')->isNotEmpty() || (float) $inv->paid_amount > 0.004;
                                            $hasBuy = $inv->transactions->where('type', 'expense')->isNotEmpty();
                                        @endphp
                                        @if ($hasPay)
                                            <div class="px-3 py-2 text-xs text-amber-800">Excluir fatura bloqueado: há pagamento registrado. Estorne o pagamento antes.</div>
                                        @elseif ($hasBuy)
                                            <div class="px-3 py-2 text-xs text-amber-800">Excluir fatura bloqueado: ainda há compras. Mova ou exclua as compras antes.</div>
                                        @else
                                            <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm text-down hover:bg-mono-50" wire:click="deleteInvoice({{ $inv->id }})" wire:confirm="Excluir a fatura {{ $inv->reference_month }}? Ela está vazia e sem pagamento." data-invoice-delete="{{ $inv->id }}" @click="open=false">Excluir fatura</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if ($expandedInvoiceId === $inv->id)
                            <div class="mt-3 grid grid-cols-1 gap-4 rounded-xl bg-mono-50 p-3 text-xs md:grid-cols-2">
                                <div data-invoice-purchases="{{ $inv->id }}">
                                    <div class="mb-1 flex flex-wrap items-center justify-between gap-2 font-semibold text-mono-700">
                                        <span>Compras ({{ $purchases->count() }})@if ($filtered) <span class="font-normal text-mono-500" data-filtered-total>· filtrado: {{ $brl($purchases->sum('amount')) }} de {{ $brl($allPurchases->sum('amount')) }}</span>@endif</span>
                                        @if ($purchases->isNotEmpty())
                                            <button type="button" class="text-xs font-semibold text-primary-600" wire:click="toggleSelectInvoice({{ $inv->id }})" data-select-invoice="{{ $inv->id }}">Marcar todas</button>
                                        @endif
                                    </div>
                                    @forelse ($purchases as $t)
                                        <div class="flex flex-col gap-1 border-b border-mono-100 py-2 last:border-0 sm:flex-row sm:items-center sm:justify-between sm:gap-2" wire:key="tx-{{ $t->id }}" data-purchase="{{ $t->id }}">
                                            <label class="flex min-w-0 flex-1 items-start gap-2 text-xs">
                                                <input type="checkbox" class="mt-0.5 h-4 w-4" value="{{ $t->id }}" wire:model.live="selectedIds" aria-label="Selecionar compra">
                                                <span>
                                                    {{ $t->date->format('d/m') }} · {{ $t->description }}
                                                    @if ($t->additional_card_id && isset($cardsById[$t->additional_card_id])) <span class="text-mono-500">· final {{ $cardsById[$t->additional_card_id]->last_four ?? $cardsById[$t->additional_card_id]->name }}</span>@endif
                                                    @if ($t->installment_total) <span class="text-mono-500">({{ $t->installment_number }}/{{ $t->installment_total }})</span>@endif
                                                    @if ($t->category)<span class="text-mono-500">· {{ $t->category->name }}</span>@endif
                                                    @if ($t->ofx_fitid)<span class="text-mono-400">· importada</span>@endif
                                                </span>
                                            </label>
                                            <div class="flex flex-wrap items-center justify-end gap-2 pl-6 sm:pl-0">
                                                <x-fx.amount :type="$t->type" :amount="$t->amount">{{ $brl($t->amount) }}</x-fx.amount>
                                                <button type="button" class="min-h-11 text-xs font-semibold text-primary-600" wire:click="startEditPurchase({{ $t->id }})" data-purchase-edit="{{ $t->id }}">Editar</button>
                                                <button type="button" class="min-h-11 text-xs font-semibold text-primary-600" wire:click="startEditPurchase({{ $t->id }})" data-purchase-move="{{ $t->id }}">Mover</button>
                                                <button type="button" class="min-h-11 text-xs font-semibold text-down" wire:click="deletePurchase({{ $t->id }})" wire:confirm="Excluir a compra {{ $t->description }} de {{ $brl($t->amount) }}?" data-purchase-delete="{{ $t->id }}">Excluir</button>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="text-mono-500">Sem compras nesta fatura.</div>
                                    @endforelse
                                </div>
                                <div>
                                    <div class="mb-1 font-semibold text-mono-700">Pagamentos ({{ $payments->count() }})</div>
                                    @forelse ($payments as $p)
                                        <div class="flex justify-between gap-2 py-0.5">
                                            <span>{{ $p->date->format('d/m/Y') }} · {{ $p->bankAccount?->name ?? 'Conta' }}@if ($p->notes) <span class="text-mono-500">· {{ $p->notes }}</span>@endif</span>
                                            <x-fx.amount :type="$p->type" :amount="$p->amount">{{ $brl($p->amount) }}</x-fx.amount>
                                        </div>
                                    @empty
                                        <div class="text-mono-500">Nenhum pagamento registrado.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-fx.card>

    @if ($paying)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelPayment" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[60px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">Pagar fatura {{ $paying->reference_month }}</h3>
                    <button type="button" class="text-mono-400" wire:click="cancelPayment" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <form wire:submit="pay" class="space-y-4 px-6 py-5">
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-mono-50 p-3 text-center text-xs">
                        <div><div class="text-mono-500">Total</div><div class="font-semibold">{{ $brl($paying->total) }}</div></div>
                        <div><div class="text-mono-500">Já pago</div><div class="font-semibold">{{ $brl($paying->paid_amount) }}</div></div>
                        <div><div class="text-mono-500">Restante</div><div class="font-semibold">{{ $brl($paying->remainingAmount()) }}</div></div>
                    </div>
                    <x-banking.account-picker :accounts="$accounts" :selected-id="$payment_account_id" model="payment_account_id" id="invoice-payment-account" label="Conta de pagamento *" />
                    @error('payment_account_id') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-fx.input label="Valor * (total ou parcial)" type="text" x-money wire:model="payment_amount" />
                            @error('payment_amount') <p class="mt-1 text-xs font-medium text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-fx.input label="Data do pagamento *" type="date" wire:model="payment_date" />
                            @error('payment_date') <p class="mt-1 text-xs font-medium text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <x-fx.input label="Observação" type="text" wire:model="payment_notes" />
                    <p class="text-xxs text-mono-500">O pagamento sai da conta escolhida como quitação da fatura. As compras já foram lançadas no cartão e não são duplicadas.</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold" wire:click="cancelPayment">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Confirmar pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if (count($selectedIds))
        <div class="sticky bottom-3 z-10 flex flex-col gap-2 rounded-2xl border border-mono-200 bg-mono-white p-3 shadow-elevated sm:flex-row sm:items-end" data-bulk-bar>
            <div class="text-sm font-semibold">{{ count($selectedIds) }} compra(s) marcada(s)</div>
            <label class="flex-1 text-xs">Mover para a fatura
                <input type="month" wire:model="move_to_month" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3 text-sm" data-bulk-month>
            </label>
            @error('move_to_month')<p class="text-xs text-error">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <button type="button" class="h-11 rounded-pill bg-primary-500 px-4 text-sm font-semibold text-white" wire:click="moveSelected" data-bulk-move>Mover</button>
                <button type="button" class="h-11 rounded-pill border border-red-200 px-4 text-sm font-semibold text-down" wire:click="deleteSelected" wire:confirm="Excluir {{ count($selectedIds) }} compra(s) marcada(s)?" data-bulk-delete>Excluir</button>
                <button type="button" class="h-11 rounded-pill border border-mono-200 px-4 text-sm" wire:click="$set('selectedIds', [])">Desmarcar</button>
            </div>
        </div>
    @endif

    @if ($editingPurchaseId)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" data-edit-purchase-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelEditPurchase" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[60px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">Editar compra</h3>
                    <button type="button" class="text-mono-400" wire:click="cancelEditPurchase" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <form wire:submit="savePurchase" class="space-y-4 px-6 py-5">
                    <x-fx.input label="Data *" type="date" wire:model.live="edit_date" />
                    @error('edit_date') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <x-fx.input label="Descrição *" type="text" wire:model="edit_description" />
                    @error('edit_description') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <x-fx.input label="Valor *" type="text" x-money wire:model="edit_amount" />
                    @error('edit_amount') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <label class="block text-sm">Categoria
                        <select wire:model="edit_category_id" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3 text-sm">
                            <option value="">Sem categoria</option>
                            @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-sm">Fatura de destino *
                        <input type="month" wire:model.live="edit_invoice_month" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3 text-sm" data-edit-invoice-month>
                    </label>
                    @error('edit_invoice_month') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    @if ($suggestedEditMonth && $edit_invoice_month && $suggestedEditMonth !== $edit_invoice_month)
                        <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900" data-invoice-mismatch>A data desta compra combina com a fatura {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $suggestedEditMonth)->translatedFormat('M/Y') }}, não com a fatura escolhida. Você pode manter ou trocar.</p>
                    @endif
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold" wire:click="cancelEditPurchase">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($editingInvoiceId)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" data-edit-invoice-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelEditInvoice" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[60px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">Editar fatura</h3>
                    <button type="button" class="text-mono-400" wire:click="cancelEditInvoice" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <form wire:submit="saveInvoice" class="space-y-4 px-6 py-5">
                    <p class="text-xs text-mono-600">Altera só as datas de fechamento e vencimento. Os totais não mudam.</p>
                    <x-fx.input label="Fechamento *" type="date" wire:model="invoice_closing_date" />
                    @error('invoice_closing_date') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <x-fx.input label="Vencimento *" type="date" wire:model="invoice_due_date" />
                    @error('invoice_due_date') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold" wire:click="cancelEditInvoice">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showUndo)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" data-undo-import-modal>
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelUndo" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[60px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">Desfazer importação</h3>
                    <button type="button" class="text-mono-400" wire:click="cancelUndo" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <div class="space-y-3 px-6 py-5">
                    <p class="text-xs text-mono-600">Remove só as compras da importação escolhida. Lançamentos feitos à mão permanecem. Os totais das faturas são recalculados. Saldos de conta não mudam.</p>
                    @forelse ($imports as $imp)
                        <label class="flex cursor-pointer flex-col gap-1 rounded-xl border p-3 text-sm {{ $undoKey === $imp['key'] ? 'border-primary-500 bg-primary-50' : 'border-mono-200' }}">
                            <span class="flex items-start gap-2">
                                <input type="radio" wire:model.live="undoKey" value="{{ $imp['key'] }}" class="mt-1">
                                <span>
                                    <strong>{{ $imp['label'] }}</strong>
                                    <span class="block text-xs text-mono-600">{{ $imp['items_count'] }} compra(s) · R$ {{ number_format($imp['total_amount'], 2, ',', '.') }} · {{ $imp['imported_at_label'] }} (horário de Brasília)</span>
                                </span>
                            </span>
                            @if ($undoKey === $imp['key'])
                                <ul class="mt-2 max-h-40 overflow-auto rounded-lg bg-mono-50 p-2 text-xs" data-undo-preview>
                                    @foreach ($imp['preview'] as $p)
                                        <li>{{ \Illuminate\Support\Carbon::parse($p['date'])->format('d/m') }} · {{ $p['description'] }} · R$ {{ number_format($p['amount'], 2, ',', '.') }} · fatura {{ $p['invoice'] ?? '—' }}</li>
                                    @endforeach
                                    @if ($imp['items_count'] > count($imp['preview']))<li>… e mais {{ $imp['items_count'] - count($imp['preview']) }}</li>@endif
                                </ul>
                            @endif
                        </label>
                    @empty
                        <p class="text-sm text-mono-600">Nenhuma importação de extrato deste cartão para desfazer.</p>
                    @endforelse
                    @error('undoKey')<p class="text-xs text-error">{{ $message }}</p>@enderror
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold" wire:click="cancelUndo">Cancelar</button>
                        <button type="button" class="h-11 rounded-pill bg-red-600 px-6 text-sm font-semibold text-white disabled:opacity-50" wire:click="confirmUndo" wire:confirm="Remover as compras desta importação? Esta ação pode ser conferida no extrato da fatura." @disabled(! $undoKey) data-confirm-undo>Desfazer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>

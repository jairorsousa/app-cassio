<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\OfxImportDraftService;
use App\Domains\Banking\Services\OfxImportService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransactionAllocationService;
use App\Domains\Banking\Services\TransferService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;
    use WithFileUploads;

    public bool $showImportModal = false;

    public $ofxFile = null;

    public ?int $ofxAccountId = null;

    public string $ofxTargetType = 'bank';

    public ?int $ofxCardId = null;

    public string $ofxInvoiceMonth = '';

    #[Url]
    public string $preview = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $account = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public bool $showFormModal = false;

    public bool $showAllocationModal = false;

    public ?int $allocatingId = null;

    public array $allocationRows = [];

    public string $allocationDescription = '';

    public string $allocationAmount = '';

    public string $allocationType = '';

    public bool $allocationExists = false;

    public bool $editingHasAllocations = false;

    public ?int $editingId = null;

    public string $formType = 'expense';

    public string $formDate = '';

    public string $formAmount = '';

    public string $formDescription = '';

    public string $formNotes = '';

    public string $formStatus = 'settled';

    public ?int $formCategoryId = null;

    public ?int $formBankAccountId = null;

    public ?int $formCreditCardId = null;

    #[\Livewire\Attributes\Locked]
    public bool $formIsCard = false;

    #[\Livewire\Attributes\Locked]
    public bool $formIsCardRefund = false;

    public string $formInvoiceMonth = '';

    public ?int $formTransferToId = null;

    public int $formInstallments = 1;

    public bool $formAllocationEnabled = false;

    public array $formAllocationRows = [];

    public string $formAllocationMode = 'amount';

    public string $allocationMode = 'amount';

    public function updatedFormAllocationMode(): void
    {
        $this->formAllocationRows = $this->convertAllocationMode($this->formAmount, $this->formAllocationRows, $this->formAllocationMode);
    }

    public function updatedAllocationMode(): void
    {
        $this->allocationRows = $this->convertAllocationMode($this->allocationAmount, $this->allocationRows, $this->allocationMode);
    }

    private function convertAllocationMode(string $amount, array $rows, string $mode): array
    {
        try {
            $service = app(TransactionAllocationService::class);
            return $mode === 'percentage' ? $service->toPercentages($amount, $rows) : $service->fromPercentages($amount, $rows, false);
        } catch (\InvalidArgumentException) {
            return $rows;
        }
    }

    public function allocationPreview(string $amount, array $rows, string $mode): array
    {
        if ($mode !== 'percentage') {
            return $rows;
        }
        try {
            return app(TransactionAllocationService::class)->fromPercentages($amount, $rows, false);
        } catch (\InvalidArgumentException) {
            return array_map(fn ($row) => array_replace($row, ['amount' => '']), $rows);
        }
    }

    public function updatedFormAllocationEnabled(): void
    {
        if ($this->formAllocationEnabled && count($this->formAllocationRows) < 2) {
            $this->formAllocationRows = [
                ['key' => (string) Str::uuid(), 'category_id' => $this->formCategoryId, 'amount' => $this->formAmount],
                ['key' => (string) Str::uuid(), 'category_id' => null, 'amount' => ''],
            ];
        }
        $this->resetValidation('formAllocationRows');
    }

    public function addFormAllocationRow(): void
    {
        if (count($this->formAllocationRows) < 20) {
            $this->formAllocationRows[] = ['key' => (string) Str::uuid(), 'category_id' => null, 'amount' => ''];
        }
    }

    public function removeFormAllocationRow(int $index): void
    {
        if (count($this->formAllocationRows) > 2 && isset($this->formAllocationRows[$index])) {
            array_splice($this->formAllocationRows, $index, 1);
        }
    }

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now()->startOfMonth()->format('Y-m-d');
            $this->to = now()->endOfMonth()->format('Y-m-d');
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'category', 'account', 'status', 'type']);
        $this->from = now()->startOfMonth()->format('Y-m-d');
        $this->to = now()->endOfMonth()->format('Y-m-d');
        $this->resetPage();
    }

    public function openImport(): void
    {
        $this->reset(['ofxFile', 'ofxAccountId', 'ofxTargetType', 'ofxCardId', 'ofxInvoiceMonth']);
        $this->resetValidation();
        $this->showImportModal = true;
    }

    public function closeImport(): void
    {
        $this->reset(['ofxFile', 'ofxAccountId', 'ofxTargetType', 'ofxCardId', 'ofxInvoiceMonth']);
        $this->resetValidation();
        $this->showImportModal = false;
    }

    public function previewImport(OfxImportService $service, OfxImportDraftService $drafts): void
    {
        $this->validateImport();

        try {
            $contents = file_get_contents($this->ofxFile->getRealPath());
            $parsed = $service->parse($contents);
            if ($parsed['statement_type'] !== $this->ofxTargetType) {
                throw new \InvalidArgumentException($parsed['statement_type'] === 'card' ? 'Este OFX é de cartão de crédito. Selecione Cartão de crédito e a fatura.' : 'Este OFX é de conta bancária. Selecione Conta bancária.');
            }
            $target = $this->ofxTargetType === 'card' ? CreditCard::active()->findOrFail($this->ofxCardId) : BankAccount::active()->findOrFail($this->ofxAccountId);
            $drafts->create($target->id, $contents, $this->ofxTargetType, $this->ofxTargetType === 'card' ? $this->ofxInvoiceMonth : null);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addError('ofxFile', $e->getMessage());

            return;
        }

        $this->redirectRoute('banking.transactions.index', ['preview' => 'ofx'], navigate: true);
    }

    private function validateImport(): void
    {
        $this->validate([
            'ofxTargetType' => 'required|in:bank,card',
            'ofxCardId' => [Rule::requiredIf($this->ofxTargetType === 'card'), 'nullable', 'integer', Rule::exists('credit_cards', 'id')->where('status', true)->whereNull('deleted_at')],
            'ofxInvoiceMonth' => [Rule::requiredIf($this->ofxTargetType === 'card'), 'nullable', 'date_format:Y-m'],
            'ofxAccountId' => [Rule::requiredIf($this->ofxTargetType === 'bank'), 'nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('status', true)->whereNull('deleted_at')],
            'ofxFile' => 'required|file|max:2048',
        ]);

        if (strtolower(pathinfo($this->ofxFile->getClientOriginalName(), PATHINFO_EXTENSION)) !== 'ofx') {
            throw \Illuminate\Validation\ValidationException::withMessages(['ofxFile' => 'Selecione um arquivo com extensão .ofx.']);
        }
    }

    public function create(string $type = 'expense'): void
    {
        abort_unless(in_array($type, ['expense', 'income', 'card_expense', 'transfer'], true), 422);

        $this->resetForm();
        $this->formIsCard = $type === 'card_expense';
        $this->formType = $this->formIsCard ? 'expense' : $type;
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $transaction = Transaction::withCount('allocations')->findOrFail($id);

        if ($transaction->isReadOnly()) {
            session()->flash('error', 'Lançamento gerado por outro módulo é somente leitura.');

            return;
        }

        $this->resetForm();
        $this->editingId = $transaction->id;
        $this->editingHasAllocations = $transaction->allocations_count > 0;
        $this->formAllocationEnabled = $this->editingHasAllocations;
        $this->formAllocationRows = $transaction->allocations->map(fn ($row) => [
            'key' => (string) Str::uuid(), 'category_id' => $row->category_id, 'amount' => (string) $row->amount,
        ])->all();
        $this->formType = $transaction->type;
        $this->formDate = $transaction->date->format('Y-m-d');
        $this->formAmount = (string) abs((float) $transaction->amount);
        $this->formDescription = $transaction->description;
        $this->formNotes = (string) $transaction->notes;
        $this->formStatus = $transaction->status;
        $this->formCategoryId = $transaction->category_id;
        $this->formBankAccountId = $transaction->bank_account_id;
        $this->formCreditCardId = $transaction->credit_card_id;
        $this->formIsCard = $transaction->type === 'expense' && $transaction->credit_card_id !== null;
        $this->formIsCardRefund = $this->formIsCard && (float) $transaction->amount < 0;
        $this->formInvoiceMonth = $transaction->invoice?->reference_month ?? '';
        if ($this->formIsCard && $this->formInvoiceMonth === '') {
            $this->suggestInvoiceMonth();
        }
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function updatedFormCreditCardId(): void
    {
        $this->suggestInvoiceMonth();
    }

    public function updatedFormAmount(): void
    {
        if (str_contains($this->formAmount, ',')) {
            $this->formAmount = str_replace(',', '.', str_replace('.', '', $this->formAmount));
        }
    }

    public function updatedFormDate(): void
    {
        $this->suggestInvoiceMonth();
        if (! $this->formIsCard) {
            $validator = validator(['date' => $this->formDate], ['date' => 'required|date_format:Y-m-d']);
            if ($validator->passes()) {
                $this->formStatus = $this->formDate <= now('America/Sao_Paulo')->format('Y-m-d') ? 'settled' : 'pending';
            }
        }
    }

    private function suggestInvoiceMonth(): void
    {
        $this->formInvoiceMonth = '';
        $card = CreditCard::find($this->formCreditCardId);
        if (! $this->formIsCard || ! $card || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->formDate)) {
            return;
        }

        try {
            $date = Carbon::parse($this->formDate);
            $this->formInvoiceMonth = ($date->day <= $card->closing_day ? $date : $date->addMonthNoOverflow())->format('Y-m');
        } catch (\Exception) {
            // A validação do formulário apresentará o erro da data.
        }
    }

    public function openAllocation(int $id): void
    {
        $transaction = Transaction::with('allocations')->findOrFail($id);

        if ($transaction->isReadOnly() || ! in_array($transaction->type, ['income', 'expense'], true)) {
            session()->flash('error', 'Este lançamento não pode ser rateado.');

            return;
        }

        $this->allocatingId = $transaction->id;
        $this->allocationMode = 'amount';
        $this->allocationDescription = $transaction->description;
        $this->allocationAmount = (string) $transaction->amount;
        $this->allocationType = $transaction->type;
        $this->allocationExists = $transaction->allocations->isNotEmpty();
        $this->allocationRows = $transaction->allocations->isNotEmpty()
            ? $transaction->allocations->map(fn ($allocation) => [
                'key' => 'allocation-'.$allocation->id,
                'category_id' => $allocation->category_id,
                'amount' => (string) $allocation->amount,
            ])->all()
            : [
                ['key' => (string) Str::uuid(), 'category_id' => $transaction->category_id, 'amount' => (string) $transaction->amount],
                ['key' => (string) Str::uuid(), 'category_id' => null, 'amount' => ''],
            ];
        $this->resetValidation();
        $this->showAllocationModal = true;
    }

    public function addAllocationRow(): void
    {
        if (count($this->allocationRows) < 20) {
            $this->allocationRows[] = ['key' => (string) Str::uuid(), 'category_id' => null, 'amount' => ''];
        }
    }

    public function removeAllocationRow(int $index): void
    {
        if (count($this->allocationRows) > 2 && isset($this->allocationRows[$index])) {
            array_splice($this->allocationRows, $index, 1);
        }
    }

    public function closeAllocation(): void
    {
        $this->reset(['showAllocationModal', 'allocatingId', 'allocationRows', 'allocationDescription', 'allocationAmount', 'allocationType', 'allocationExists', 'allocationMode']);
        $this->resetValidation();
    }

    public function saveAllocation(TransactionAllocationService $service): void
    {
        $this->validate(['allocationMode' => 'required|in:amount,percentage']);
        try {
            $rows = $this->allocationMode === 'percentage' ? $service->fromPercentages($this->allocationAmount, $this->allocationRows) : $this->allocationRows;
            $service->replace(Transaction::findOrFail($this->allocatingId), $rows);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addError('allocationRows', $e->getMessage());

            return;
        }

        $this->closeAllocation();
        session()->flash('status', 'Rateio salvo.');
    }

    public function clearAllocation(TransactionAllocationService $service): void
    {
        $service->clear(Transaction::findOrFail($this->allocatingId));
        $this->closeAllocation();
        session()->flash('status', 'Rateio removido. O lançamento ficou sem categoria.');
    }

    public function saveTransaction(TransactionService $service, TransferService $transfer, InstallmentService $installment, TransactionAllocationService $allocations): void
    {
        if ($this->formType === 'transfer') {
            $this->formCategoryId = null;
            $this->formAllocationEnabled = false;
        }
        if ($this->formAllocationEnabled) {
            $this->formCategoryId = null;
        }

        if ($this->formIsCard) {
            $this->formType = 'expense';
            $this->formBankAccountId = null;
            $this->formStatus = 'settled';
            if ($this->formIsCardRefund) {
                $this->formAllocationEnabled = false;
            }
        } else {
            $this->formCreditCardId = null;
        }

        $data = $this->validate([
            'formType' => 'required|in:income,expense,transfer',
            'formDate' => 'required|date',
            'formAmount' => 'required|numeric|min:0.01',
            'formDescription' => [Rule::requiredIf($this->formType !== 'transfer'), 'nullable', 'string', 'max:200'],
            'formNotes' => 'nullable|string',
            'formStatus' => 'required|in:pending,settled',
            'formCategoryId' => ['nullable', Rule::exists('categories', 'id')->when(
                in_array($this->formType, ['income', 'expense'], true),
                fn ($rule) => $rule->where('type', $this->formType),
            )],
            'formBankAccountId' => 'nullable|exists:bank_accounts,id',
            'formCreditCardId' => [Rule::requiredIf($this->formIsCard), 'nullable', Rule::exists('credit_cards', 'id')->whereNull('deleted_at')->when(! $this->editingId, fn ($rule) => $rule->where('status', true))],
            'formInvoiceMonth' => [Rule::requiredIf($this->formIsCard), 'nullable', 'date_format:Y-m'],
            'formTransferToId' => 'nullable|exists:bank_accounts,id|different:formBankAccountId',
            'formInstallments' => 'required|integer|min:1|max:36',
            'formAllocationMode' => 'required|in:amount,percentage',
        ]);

        $allocationRows = $this->formAllocationRows;
        if ($this->formAllocationEnabled) {
            try {
                if ($this->formAllocationMode === 'percentage') {
                    $allocationRows = $allocations->fromPercentages($this->formAmount, $allocationRows);
                }
                $allocations->validateRows($this->formType, $this->formAmount, $allocationRows);
            } catch (\InvalidArgumentException $e) {
                $this->addError('formAllocationRows', $e->getMessage());
                return;
            }
        }

        try {
            $message = \Illuminate\Support\Facades\DB::transaction(function () use ($service, $transfer, $installment, $allocations, $data, $allocationRows) {
                $saved = [];
                if ($this->editingId) {
                    $transaction = Transaction::withCount('allocations')->findOrFail($this->editingId);

                    if ($transaction->allocations_count > 0 && $data['formType'] !== $transaction->type) {
                        throw new \DomainException('Remova o rateio antes de alterar o tipo do lançamento.');
                    }

                    if ($transaction->allocations_count > 0) {
                        $allocations->clear($transaction);
                    }
                    $updates = [
                        'type' => $data['formType'],
                        'date' => $data['formDate'],
                        'amount' => ($this->formIsCardRefund ? '-' : '').$data['formAmount'],
                        'description' => $data['formDescription'],
                        'notes' => $data['formNotes'] ?: null,
                        'status' => $data['formStatus'],
                        'category_id' => $data['formCategoryId'],
                        'bank_account_id' => $data['formBankAccountId'],
                        'credit_card_id' => $data['formCreditCardId'],
                    ];
                    if ($this->formIsCard) {
                        $updates['credit_card_invoice_id'] = app(InvoiceService::class)->findOrCreateForReference(
                            CreditCard::findOrFail($data['formCreditCardId']),
                            Carbon::createFromFormat('!Y-m', $data['formInvoiceMonth']),
                        )->id;
                    }
                    $saved = [$service->update($transaction, $updates)];
                    $message = 'Lançamento atualizado.';
                } elseif ($data['formType'] === 'transfer') {
                    if (! $data['formBankAccountId'] || ! $data['formTransferToId']) {
                        $this->addError('formTransferToId', 'Selecione as contas de origem e destino.');

                        return;
                    }

                    $transfer->execute(
                        BankAccount::findOrFail($data['formBankAccountId']),
                        BankAccount::findOrFail($data['formTransferToId']),
                        (float) $data['formAmount'],
                        $data['formDate'],
                        null,
                        $data['formNotes'] ?: null,
                        $data['formStatus'],
                    );
                    $message = 'Transferência criada.';
                } elseif ($data['formType'] === 'expense' && $data['formCreditCardId']) {
                    $saved = $installment->split(
                        CreditCard::findOrFail($data['formCreditCardId']),
                        Carbon::parse($data['formDate']),
                        (float) $data['formAmount'],
                        $data['formInstallments'],
                        $data['formDescription'],
                        $data['formCategoryId'],
                        $data['formNotes'] ?: null,
                        $data['formInvoiceMonth'],
                    );
                    $message = $data['formInstallments'] > 1 ? 'Compra parcelada criada.' : 'Lançamento criado.';
                } else {
                    $saved = [$service->create([
                        'type' => $data['formType'],
                        'date' => $data['formDate'],
                        'amount' => $data['formAmount'],
                        'description' => $data['formDescription'],
                        'notes' => $data['formNotes'] ?: null,
                        'status' => $data['formStatus'],
                        'category_id' => $data['formCategoryId'],
                        'bank_account_id' => $data['formBankAccountId'],
                    ])];
                    $message = 'Lançamento criado.';
                }

                if ($this->formAllocationEnabled) {
                    $allocations->replaceForTransactions($saved, $allocationRows);
                }
                return $message;
            });
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addError('formAllocationRows', $e->getMessage());
            return;
        }
        if ($message === null) {
            return;
        }

        $this->resetForm();
        $this->resetPage();
        session()->flash('status', $message);
    }

    private function resetForm(): void
    {
        $this->reset([
            'showFormModal', 'editingId', 'formAmount', 'formDescription', 'formNotes',
            'formCategoryId', 'formBankAccountId', 'formCreditCardId', 'formTransferToId', 'editingHasAllocations', 'formIsCard', 'formIsCardRefund', 'formInvoiceMonth', 'formAllocationEnabled', 'formAllocationRows', 'formAllocationMode',
        ]);
        $this->formType = 'expense';
        $this->formDate = now('America/Sao_Paulo')->format('Y-m-d');
        $this->formStatus = 'settled';
        $this->formInstallments = 1;
        $this->resetValidation();
    }

    public function delete(int $id, TransactionService $service): void
    {
        try {
            $service->delete(Transaction::findOrFail($id));
            session()->flash('status', 'Lançamento excluído.');
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(): array
    {
        if ($this->preview === 'ofx') {
            return [];
        }

        $q = Transaction::with(['category', 'allocations.category', 'bankAccount', 'creditCard']);

        if ($this->from) {
            $q->where('date', '>=', $this->from);
        }
        if ($this->to) {
            $q->where('date', '<=', $this->to);
        }
        if ($this->category) {
            $q->where(function ($query) {
                $query->where('category_id', $this->category)
                    ->orWhereHas('allocations', fn ($allocations) => $allocations->where('category_id', $this->category));
            });
        }
        if ($this->account) {
            $q->where('bank_account_id', $this->account);
        }
        if ($this->status) {
            $q->where('status', $this->status);
        }
        if ($this->type) {
            $q->where('type', $this->type);
        }

        return [
            'transactions' => $q->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'categories' => Category::orderBy('name')->get(),
            'accounts' => BankAccount::orderBy('name')->get(),
            'activeCategories' => Category::active()->orderBy('name')->get(),
            'activeAccounts' => BankAccount::active()->orderBy('name')->get(),
            'cards' => CreditCard::where(fn ($q) => $q->where('status', true)->when($this->editingId && $this->formCreditCardId, fn ($q) => $q->orWhere('id', $this->formCreditCardId)))->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $preview === 'ofx' ? 'Prévia da importação OFX' : 'Financeiro' }}</x-slot>

<div class="flex flex-col gap-space-5">
@if ($preview === 'ofx')
    <livewire:banking.transactions.import-preview />
@else
    <x-banking.subnav />
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex justify-between items-center mb-space-4">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Filtros</h3>
            <div class="flex items-center gap-2">
                <x-jr.button type="button" size="sm" wire:click="openImport">
                    <span class="material-icons-outlined text-[18px]">upload_file</span>
                    Importar OFX
                </x-jr.button>
                <x-banking.new-transaction size="sm" />
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-space-3">
            <x-fx.input label="De" type="date" wire:model.live="from" />
            <x-fx.input label="Até" type="date" wire:model.live="to" />
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Tipo</label>
                <select wire:model.live="type" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todos</option>
                    <option value="income">Receita</option>
                    <option value="expense">Despesa</option>
                    <option value="transfer">Transferência</option>
                    <option value="invoice_payment">Pagto fatura</option>
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Categoria</label>
                <select wire:model.live="category" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todas</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Conta</label>
                <select wire:model.live="account" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-space-1">
                <label class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Status</label>
                <select wire:model.live="status" class="h-[48px] px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">Todos</option>
                    <option value="settled">Liquidado</option>
                    <option value="pending">Pendente</option>
                </select>
            </div>
        </div>
        <div class="mt-space-3">
            <button class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors" wire:click="clearFilters">Limpar filtros</button>
        </div>
    </x-fx.card>

    <x-fx.card>
        @if ($transactions->isEmpty())
            <x-fx.empty-state
                icon="📋"
                title="Nenhum lançamento no período"
                description="Ajuste os filtros acima ou registre um novo lançamento.">
                <x-banking.new-transaction class="mt-space-4" align="left" />
            </x-fx.empty-state>
        @else
            <x-fx.table :headers="['Data', 'Descrição', 'Categoria', 'Conta', 'Valor', '']">
                @foreach ($transactions as $t)
                    <tr>
                        <td class="px-space-4 py-space-3 text-fs-14 font-mono text-cryptex-text-secondary whitespace-nowrap">{{ $t->date->format('d/m/Y') }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14">
                            <span class="text-cryptex-text-primary">{{ $t->description }}</span>
                            @if ($t->isInstallment())
                                <span class="text-fs-12 text-cryptex-text-tertiary ml-1 font-mono">{{ $t->installment_number }}/{{ $t->installment_total }}</span>
                            @endif
                            @if ($t->isReadOnly())
                                <x-fx.badge variant="neutral" class="ml-space-2">origem: {{ class_basename($t->source_type) }}</x-fx.badge>
                            @endif
                            @if ($t->ofx_fitid)
                                <x-fx.badge variant="neutral" class="ml-space-2">OFX</x-fx.badge>
                            @endif
                            @if ($t->allocations->isNotEmpty())
                                <x-fx.badge variant="neutral" class="ml-space-2">rateado</x-fx.badge>
                            @endif
                            @if ($t->status === 'pending')
                                <x-fx.badge variant="warning" class="ml-space-2">pendente</x-fx.badge>
                            @endif
                        </td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">
                            @if ($t->allocations->isNotEmpty())
                                <details>
                                    <summary class="cursor-pointer text-primary-600">{{ $t->allocations->count() }} categorias</summary>
                                    <div class="mt-2 space-y-1">
                                        @foreach ($t->allocations as $allocation)
                                            <div>{{ $allocation->category?->name ?? 'Sem categoria' }}: R$ {{ number_format((float) $allocation->amount, 2, ',', '.') }}</div>
                                        @endforeach
                                    </div>
                                </details>
                            @else
                                {{ $t->category?->name }}
                            @endif
                        </td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $t->bankAccount?->name ?? $t->creditCard?->name }}</td>
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums] {{ $t->type === 'income' ? 'text-cryptex-green-500' : ($t->type === 'transfer' ? 'text-cryptex-text-primary' : 'text-cryptex-red-500') }}">
                            R$ {{ number_format(abs((float) $t->amount), 2, ',', '.') }}
                        </td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            @unless ($t->isReadOnly())
                                <button type="button" wire:click="edit({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">Editar</button>
                                @if (in_array($t->type, ['income', 'expense'], true))
                                    <button type="button" wire:click="openAllocation({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">{{ $t->allocations->isNotEmpty() ? 'Editar rateio' : 'Ratear' }}</button>
                                @endif
                                <button class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $t->id }})" wire:confirm="Excluir lançamento?">Excluir</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </x-fx.table>
            <div class="mt-space-4">{{ $transactions->links() }}</div>
        @endif
    </x-fx.card>

    @if ($showImportModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="closeImport" aria-label="Fechar importação"></button>
            <div class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
                    <h3 class="text-lg font-bold text-mono-900">Importar extrato OFX</h3>
                    <button type="button" wire:click="closeImport" aria-label="Fechar" class="text-mono-500 hover:text-mono-900"><span class="material-icons-outlined">close</span></button>
                </div>
                <div class="overflow-y-auto px-6 py-5 space-y-5">
                    <div>
                        <label for="ofx-target-type" class="mb-2 block text-sm font-medium text-mono-600">Importar para</label>
                        <select id="ofx-target-type" wire:model.live="ofxTargetType" class="h-12 w-full rounded-pill border border-mono-200 bg-white px-4 text-sm">
                            <option value="bank">Conta bancária</option><option value="card">Cartão de crédito</option>
                        </select>
                    </div>
                    @if ($ofxTargetType === 'card')
                        <div>
                            <label for="ofx-card" class="mb-2 block text-sm font-medium text-mono-600">Cartão de crédito *</label>
                            <select id="ofx-card" wire:model="ofxCardId" class="h-12 w-full rounded-pill border border-mono-200 bg-white px-4 text-sm">
                                <option value="">Selecione o cartão</option>
                                @foreach ($cards as $cardOption)<option value="{{ $cardOption->id }}">{{ $cardOption->name }}</option>@endforeach
                            </select>
                            @error('ofxCardId') <p class="mt-2 text-xs text-error">{{ $message }}</p> @enderror
                        </div>
                        <x-jr.input label="Mês de referência da fatura *" type="month" name="ofxInvoiceMonth" wire:model="ofxInvoiceMonth" helper="Selecione o mês do fechamento da fatura. O vencimento segue a configuração do cartão." required />
                        <p class="text-sm text-mono-600">As compras serão vinculadas a esta fatura. Parcelas presentes no arquivo serão importadas uma única vez. Pagamentos da fatura serão identificados na prévia e não serão importados como compras.</p>
                    @else
                    <p class="text-sm text-mono-600">Escolha a conta de destino e confira os lançamentos antes de cadastrar. Reimportações da mesma conta são ignoradas pelo identificador do OFX. Confira possíveis lançamentos manuais equivalentes antes de confirmar.</p>
                    <div>
                        <label for="ofx-account" class="mb-2 block text-sm font-medium text-mono-600">Conta bancária *</label>
                        <select id="ofx-account" wire:model.live="ofxAccountId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900">
                            <option value="">Selecione a conta</option>
                            @foreach ($activeAccounts as $accountOption)
                                <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                            @endforeach
                        </select>
                        @error('ofxAccountId') <p class="mt-2 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                    @endif
                    <div>
                        <label for="ofx-file" class="mb-2 block text-sm font-medium text-mono-600">Arquivo OFX (até 2 MB) *</label>
                        <input id="ofx-file" type="file" accept=".ofx" wire:model="ofxFile" class="block w-full rounded-xl border border-mono-200 p-3 text-sm text-mono-700">
                        <p wire:loading wire:target="ofxFile" class="mt-2 text-xs text-mono-500">Carregando arquivo...</p>
                        @error('ofxFile') <p class="mt-2 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                    <button type="button" wire:click="closeImport" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900">Cancelar</button>
                    <button type="button" wire:click="previewImport" wire:loading.attr="disabled" wire:target="previewImport,ofxFile" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Ver prévia completa</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showAllocationModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="closeAllocation" aria-label="Fechar rateio"></button>
            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
                    <h3 class="text-lg font-bold text-mono-900">Ratear lançamento</h3>
                    <button type="button" wire:click="closeAllocation" aria-label="Fechar" class="text-mono-500 hover:text-mono-900"><span class="material-icons-outlined">close</span></button>
                </div>
                <form wire:submit="saveAllocation" class="flex min-h-0 flex-1 flex-col">
                    <div class="space-y-5 overflow-y-auto px-6 py-5">
                        <div class="rounded-xl bg-mono-50 p-4">
                            <p class="text-sm font-semibold text-mono-900">{{ $allocationDescription }}</p>
                            <p class="mt-1 text-sm text-mono-600">Valor total: <strong>R$ {{ number_format((float) $allocationAmount, 2, ',', '.') }}</strong></p>
                        </div>

                        <p class="text-sm text-mono-600">Distribua o valor entre duas ou mais categorias. O lançamento continuará único na conta bancária.</p>

                        <x-banking.allocation-mode model="allocationMode" id="saved-allocation-mode" />
                        @php $allocationPreviewRows = $this->allocationPreview($allocationAmount, $allocationRows, $allocationMode); @endphp
                        <div class="space-y-3">
                            @foreach ($allocationRows as $index => $row)
                                <div wire:key="allocation-row-{{ $row['key'] }}-{{ $index }}-{{ $allocationMode }}" class="grid grid-cols-1 gap-3 rounded-xl border border-mono-100 p-3 sm:grid-cols-[1fr_150px_36px] sm:items-end">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-mono-600" for="allocation-category-{{ $row['key'] }}">Categoria {{ $index + 1 }}</label>
                                        <select id="allocation-category-{{ $row['key'] }}" wire:model.change="allocationRows.{{ $index }}.category_id" class="h-10 w-full rounded-xl border border-mono-200 bg-white px-3 text-sm text-mono-900">
                                            <option value="">Selecione</option>
                                            @foreach ($activeCategories->where('type', $allocationType) as $categoryOption)
                                                <option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-mono-600" for="allocation-amount-{{ $row['key'] }}">{{ $allocationMode === 'percentage' ? 'Percentual (%)' : 'Valor (R$)' }}</label>
                                        @if ($allocationMode === 'percentage')
                                            <input id="allocation-amount-{{ $row['key'] }}" type="number" inputmode="decimal" min="0.01" max="100" step="0.01" wire:model.live.debounce.300ms="allocationRows.{{ $index }}.percentage" class="h-10 w-full rounded-xl border border-mono-200 bg-white px-3 text-sm text-mono-900" placeholder="0,00" required>
                                            <p class="mt-1 text-xs text-mono-600">R$ {{ number_format((float) ($allocationPreviewRows[$index]['amount'] ?? 0), 2, ',', '.') }}</p>
                                        @else
                                            <input id="allocation-amount-{{ $row['key'] }}" type="number" inputmode="decimal" min="0.01" step="0.01" wire:model.live.debounce.300ms="allocationRows.{{ $index }}.amount" class="h-10 w-full rounded-xl border border-mono-200 bg-white px-3 text-sm text-mono-900" placeholder="0,00" required>
                                        @endif
                                    </div>
                                    <button type="button" wire:click="removeAllocationRow({{ $index }})" @disabled(count($allocationRows) <= 2) aria-label="Remover linha {{ $index + 1 }}" class="flex h-10 w-10 items-center justify-center rounded-xl text-mono-500 hover:bg-mono-100 disabled:opacity-30"><span class="material-icons-outlined">close</span></button>
                                </div>
                            @endforeach
                        </div>

                        <button type="button" wire:click="addAllocationRow" @disabled(count($allocationRows) >= 20) class="text-sm font-semibold text-primary-600 disabled:opacity-50">+ Adicionar categoria</button>

                        @php
                            $allocated = collect($allocationPreviewRows)->sum(fn ($row) => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0);
                            $remaining = (float) $allocationAmount - $allocated;
                        @endphp
                        <div class="flex flex-wrap justify-between gap-2 rounded-xl bg-mono-50 p-4 text-sm">
                            <span>Rateado: <strong>R$ {{ number_format($allocated, 2, ',', '.') }}</strong></span>
                            <span class="{{ abs($remaining) < 0.005 ? 'text-green-600' : 'text-error' }}">Restante: <strong>R$ {{ number_format($remaining, 2, ',', '.') }}</strong></span>
                        </div>

                        @if ($allocationMode === 'percentage')
                            <p class="text-sm text-mono-600">Total dos percentuais: <strong>{{ number_format(collect($allocationRows)->sum(fn ($row) => is_numeric($row['percentage'] ?? null) ? (float) $row['percentage'] : 0), 2, ',', '.') }}%</strong> de 100%</p>
                        @endif
                        @error('allocationRows') <p class="text-sm font-medium text-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        @if ($allocationExists)
                            <button type="button" wire:click="clearAllocation" wire:confirm="Remover o rateio? O lançamento ficará sem categoria." class="mr-auto text-sm font-semibold text-error">Remover rateio</button>
                        @endif
                        <button type="button" wire:click="closeAllocation" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Salvar rateio</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showFormModal)
        @php
            $formHeading = $formIsCard ? ($editingId ? 'Editar despesa no cartão' : 'Nova despesa no cartão de crédito') : ($editingId ? 'Editar Lançamento' : match ($formType) {
                'income' => 'Nova receita',
                'transfer' => 'Nova transferência',
                default => 'Nova despesa',
            });
            $formColor = $formIsCard ? 'text-teal-600' : match ($formType) {
                'income' => 'text-green-600',
                'transfer' => 'text-blue-500',
                default => 'text-red-500',
            };
            $formIcon = $formIsCard ? 'credit_card' : match ($formType) {
                'income' => 'trending_up',
                'transfer' => 'sync_alt',
                default => 'trending_down',
            };
        @endphp
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" x-data @keydown.escape.window=" $wire.cancel()">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div role="dialog" aria-modal="true" aria-labelledby="transaction-form-title" x-trap.inert.noscroll="true" class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 id="transaction-form-title" class="flex items-center gap-3 text-lg font-bold text-mono-900">
                        <span class="material-icons-outlined text-[24px] {{ $formColor }}" aria-hidden="true">{{ $formIcon }}</span>
                        {{ $formHeading }}
                    </h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="saveTransaction" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">receipt_long</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados do lançamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <x-jr.input label="Valor *" icon="payments" name="formAmount" wire:model.live.debounce.300ms="formAmount" x-money required />
                                    <x-jr.input label="Data *" icon="calendar_month" name="formDate" type="date" wire:model.live="formDate" required />

                                    @if ($formType !== 'transfer')
                                    <div class="md:col-span-2">
                                        <x-jr.input label="Descrição *" icon="description" name="formDescription" wire:model="formDescription" maxlength="200" required />
                                    </div>
                                    @endif

                                    @if (! $formIsCard)
                                        <div x-data="{ status: $wire.entangle('formStatus') }">
                                            <button type="button" role="switch" :aria-checked="status === 'settled'"
                                                @click="status = status === 'settled' ? 'pending' : 'settled'"
                                                class="inline-flex min-h-12 items-center gap-3 rounded-xl text-sm font-semibold text-mono-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">
                                                <span class="relative inline-flex h-7 w-12 shrink-0 rounded-full transition-colors"
                                                    :class="status === 'settled' ? 'bg-primary-500' : 'bg-mono-200'" aria-hidden="true">
                                                    <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow-sm transition-transform"
                                                        :class="status === 'settled' ? 'translate-x-5' : 'translate-x-0'"></span>
                                                </span>
                                                {{ $formType === 'income' ? 'Já foi recebido' : 'Já foi pago' }}
                                            </button>
                                            @error('formStatus') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>
                                    @endif
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance_wallet</span>
                                    <h4 class="text-base font-bold text-mono-900">Classificação e pagamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    @if (in_array($formType, ['income', 'expense'], true))
                                        @if (! $formIsCardRefund)
                                        <div class="md:col-span-2">
                                            <label class="inline-flex min-h-10 cursor-pointer items-center gap-3 text-sm font-semibold text-mono-900">
                                                <input type="checkbox" wire:model.live="formAllocationEnabled" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500">
                                                Rateio entre categorias
                                            </label>
                                        </div>
                                        @endif
                                        @if ($formAllocationEnabled)
                                            <div class="space-y-3 rounded-2xl border border-mono-100 bg-mono-50 p-4 md:col-span-2">
                                                <x-banking.allocation-mode model="formAllocationMode" id="form-allocation-mode" />
                                                <p class="text-sm text-mono-600">{{ $formAllocationMode === 'percentage' ? 'Informe o percentual de cada categoria. A soma deve ser 100%. Os valores serão recalculados quando o total do lançamento mudar.' : 'Distribua o valor total entre duas ou mais categorias. A soma deve ser igual ao total do lançamento.' }}</p>
                                                @php $formAllocationPreviewRows = $this->allocationPreview($formAmount, $formAllocationRows, $formAllocationMode); @endphp
                                                @foreach ($formAllocationRows as $index => $row)
                                                    <div wire:key="form-allocation-{{ $row['key'] }}-{{ $index }}-{{ $formAllocationMode }}" class="grid grid-cols-1 gap-3 rounded-xl bg-white p-3 sm:grid-cols-[1fr_160px_40px] sm:items-end">
                                                        <x-banking.category-picker :categories="$activeCategories->where('type', $formType)"
                                                            :model="'formAllocationRows.'.$index.'.category_id'" :id="'form-allocation-'.$row['key']" :label="'Categoria '.($index + 1)" />
                                                        @if ($formAllocationMode === 'percentage')
                                                            <x-jr.input label="Percentual (%)" :name="'formAllocationRows.'.$index.'.percentage'" type="number" min="0.01" max="100" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="formAllocationRows.{{ $index }}.percentage" :helper="'R$ '.number_format((float) ($formAllocationPreviewRows[$index]['amount'] ?? 0), 2, ',', '.')" required />
                                                        @else
                                                            <x-jr.input label="Valor (R$)" :name="'formAllocationRows.'.$index.'.amount'" type="number" min="0.01" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="formAllocationRows.{{ $index }}.amount" required />
                                                        @endif
                                                        <button type="button" wire:click="removeFormAllocationRow({{ $index }})" @disabled(count($formAllocationRows) <= 2) aria-label="Remover categoria {{ $index + 1 }}" class="flex h-12 w-10 items-center justify-center rounded-xl text-mono-500 hover:bg-mono-100 disabled:opacity-30"><span class="material-icons-outlined" aria-hidden="true">close</span></button>
                                                    </div>
                                                @endforeach
                                                <button type="button" wire:click="addFormAllocationRow" @disabled(count($formAllocationRows) >= 20) class="text-sm font-semibold text-primary-600 disabled:opacity-50">+ Adicionar categoria</button>
                                                @php
                                                    $allocated = collect($formAllocationPreviewRows)->sum(fn ($row) => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0);
                                                    $remaining = (is_numeric($formAmount) ? (float) $formAmount : 0) - $allocated;
                                                @endphp
                                                <div class="flex flex-wrap justify-between gap-2 text-sm text-mono-900">
                                                    <span>Rateado: <strong>R$ {{ number_format($allocated, 2, ',', '.') }}</strong></span>
                                                    <span class="{{ abs($remaining) < 0.005 ? 'text-green-600' : 'text-error' }}">{{ $remaining < -0.005 ? 'Excedente' : 'Restante' }}: <strong>R$ {{ number_format(abs($remaining), 2, ',', '.') }}</strong></span>
                                                </div>
                                                @if ($formAllocationMode === 'percentage')
                                                    <p class="text-sm text-mono-600">Total dos percentuais: <strong>{{ number_format(collect($formAllocationRows)->sum(fn ($row) => is_numeric($row['percentage'] ?? null) ? (float) $row['percentage'] : 0), 2, ',', '.') }}%</strong> de 100%</p>
                                                    <p class="text-xs text-mono-600">Diferenças de arredondamento serão ajustadas automaticamente nos centavos.</p>
                                                @endif
                                                @if ($formIsCard && ! $editingId)
                                                    <p class="text-xs text-mono-600">Em compras parceladas, o rateio será distribuído entre as parcelas, preservando os totais de cada categoria.</p>
                                                @endif
                                                @error('formAllocationRows') <p class="text-sm font-medium text-error" role="alert">{{ $message }}</p> @enderror
                                            </div>
                                        @else
                                            <div>
                                                <x-banking.category-picker :categories="$activeCategories->where('type', $formType)" />
                                                @error('formCategoryId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            </div>
                                        @endif
                                    @endif

                                    @if ($formType === 'transfer')
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Conta de origem *</label>
                                            <select wire:model="formBankAccountId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                                <option value="">Selecionar</option>
                                                @foreach ($activeAccounts as $accountOption)
                                                    <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('formBankAccountId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Conta de destino *</label>
                                            <select wire:model="formTransferToId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                                <option value="">Selecionar</option>
                                                @foreach ($activeAccounts as $accountOption)
                                                    <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('formTransferToId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                    @elseif ($formIsCard)
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-mono-600">Cartão de crédito *</label>
                                                <select wire:model.live="formCreditCardId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                                    <option value="">Selecionar cartão</option>
                                                    @foreach ($cards as $cardOption)
                                                        <option value="{{ $cardOption->id }}">{{ $cardOption->name }}</option>
                                                    @endforeach
                                                </select>
                                                @error('formCreditCardId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            </div>

                                            @if (! $editingId)
                                                <x-jr.input label="Parcelas" helper="Use 1 para uma compra sem parcelamento." icon="view_week" name="formInstallments" type="number" min="1" max="36" wire:model="formInstallments" />
                                            @endif
                                        <x-jr.input :label="$editingId ? 'Fatura *' : 'Primeira fatura *'" icon="receipt_long" name="formInvoiceMonth" type="month" wire:model="formInvoiceMonth" required />
                                        <div class="md:col-span-2 rounded-xl bg-teal-50 p-4 text-sm text-teal-800">
                                            @if ($editingId)
                                                As alterações serão aplicadas somente a este lançamento. O valor será atualizado na fatura selecionada.
                                            @else
                                                A compra será lançada na fatura do cartão. Para parcelar, informe o valor total e a quantidade de parcelas; cada parcela irá para uma fatura mensal.
                                            @endif
                                        </div>
                                        @if ($cards->isEmpty())
                                            <p class="md:col-span-2 text-sm text-error">Cadastre um cartão de crédito ativo na seção Cartões para registrar uma compra.</p>
                                        @endif
                                    @else
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Conta bancária</label>
                                            <select wire:model="formBankAccountId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                                <option value="">Nenhuma</option>
                                                @foreach ($activeAccounts as $accountOption)
                                                    <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('formBankAccountId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                    @endif
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">notes</span>
                                    <h4 class="text-base font-bold text-mono-900">Observações</h4>
                                </div>

                                <label class="sr-only" for="formNotes">Observações</label>
                                <textarea id="formNotes" name="formNotes" wire:model="formNotes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3" placeholder="Informações adicionais sobre o lançamento"></textarea>
                                @error('formNotes') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            {{ $editingId ? 'Salvar Lançamento' : 'Criar Lançamento' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endif
</div>

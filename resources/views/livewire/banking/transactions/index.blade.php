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

    /**
     * Tipo do filtro Conta/Cartão: '' = todas; checking|savings|investment|fund|cash|repasse|card.
     * Persistido na URL. Seleção de cartão usa account=card:{id} (evita colisão com id de conta).
     */
    #[Url]
    public string $accountType = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    /** Gastos por pessoa: titular do cartão (chave de CreditCard::holderKey). */
    #[Url]
    public string $titular = '';

    public function updatedTitular(): void
    {
        $this->resetPage();
    }

    /** '' = oculta testes; '1' = mostra também os lançamentos marcados como teste. */
    #[Url]
    public string $testes = '';

    /** '' = todos; 'ocultar' = sem históricos; 'somente' = só históricos (antes do saldo inicial). */
    #[Url]
    public string $historico = '';

    public bool $showFormModal = false;

    public bool $showAllocationModal = false;

    public ?int $distributingId = null;

    #[\Livewire\Attributes\Locked]
    public ?int $deletingId = null;

    /** Modo "lançamento rápido": instância global no layout, só com o modal (sem lista, filtros nem URL própria). */
    #[\Livewire\Attributes\Locked]
    public bool $quick = false;

    public function openDistribution(int $id): void
    {
        try {
            app(\App\Domains\Banking\Services\ReceiptDestinationService::class)->eligible(Transaction::findOrFail($id));
            $this->distributingId = $id;
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[\Livewire\Attributes\On('receipt-destination-closed')]
    public function closeDistribution(): void
    {
        $this->distributingId = null;
    }

    #[\Livewire\Attributes\On('receipt-destination-updated')]
    public function refreshDestinations(): void {}

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

    /** Quando true, saveTransaction reabre o formulário em branco após gravar com sucesso. */
    public bool $createAnotherAfterSave = false;

    /** Feedback intermediário dentro do modal (Salvar e novo). */
    public ?string $formSuccessMessage = null;

    public bool $formAllocationEnabled = false;

    /** Vínculos opcionais do lançamento (Etapa 2). */
    public string $formLinkProcess = '';

    public ?int $formLinkWritId = null;

    public ?int $formLinkBrokerId = null;

    public ?int $formLinkContactId = null;

    public string $linkSearch = '';

    /** Seção opcional "Vínculos e comprovante" recolhida por padrão. */
    public bool $showOptionalLinks = false;

    /** Comprovante (PDF, JPG ou PNG), guardado no disco privado. */
    public $formAttachment = null;

    /** Filtro por vínculo: "writ:ID", "broker:ID", "contact:ID" ou "process:texto". */
    #[Url]
    public string $vinculo = '';

    public bool $formDestinateAfterSave = false;
    #[\Livewire\Attributes\Locked]
    public bool $editingProcessReceipt = false;

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

    public function mount(bool $quick = false): void
    {
        $this->quick = $quick;
        if ($quick) {
            return;
        }

        $distribuir = request()->query('distribuir');
        if (is_numeric($distribuir)) {
            $this->openDistribution((int) $distribuir);
        }

        if ($this->from === '' && $this->vinculo === '') {
            $this->from = now()->startOfMonth()->format('Y-m-d');
            $this->to = now()->endOfMonth()->format('Y-m-d');
        }

        $editar = request()->query('editar');
        if (is_numeric($editar)) {
            $this->edit((int) $editar);
        }

        $novo = request()->query('novo');
        if (is_string($novo) && in_array($novo, ['expense', 'income', 'transfer', 'card_expense'], true)) {
            $this->create($novo);
        }
    }

    /**
     * Pills horizontais curtas. Só tipos existentes no cadastro (+ Cartão se houver).
     *
     * @return array<string, string>
     */
    public function accountTypeFilterOptions(): array
    {
        $options = ['' => 'Todas'];
        $present = BankAccount::query()->select('type')->distinct()->pluck('type')->all();
        foreach (BankAccount::TYPE_ORDER as $type) {
            if (in_array($type, $present, true)) {
                $options[$type] = (new BankAccount(['type' => $type]))->typeShortLabel();
            }
        }
        if (CreditCard::query()->exists()) {
            $options['card'] = 'Cartão';
        }

        return $options;
    }

    public function updatedAccountType(): void
    {
        $allowed = array_keys($this->accountTypeFilterOptions());
        if (! in_array($this->accountType, $allowed, true)) {
            $this->accountType = '';
        }

        $account = trim((string) $this->account);
        $isCardValue = str_starts_with($account, 'card:');

        if ($this->accountType === 'card') {
            if ($account !== '' && ! $isCardValue) {
                $this->account = ctype_digit($account) && CreditCard::whereKey((int) $account)->exists()
                    ? 'card:'.$account
                    : '';
            } elseif ($isCardValue && ! CreditCard::whereKey((int) substr($account, 5))->exists()) {
                $this->account = '';
            }
        } elseif ($this->accountType !== '') {
            if ($isCardValue) {
                $this->account = '';
            } elseif ($account !== '' && ctype_digit($account)) {
                if (BankAccount::whereKey((int) $account)->value('type') !== $this->accountType) {
                    $this->account = '';
                }
            } elseif ($account !== '') {
                $this->account = '';
            }
        }

        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    /** Aplica filtro de conta bancária e/ou cartão (tipo + item específico). */
    private function applyAccountOrCardFilter($query): void
    {
        $type = $this->accountType;
        $account = trim((string) $this->account);
        $isCardValue = str_starts_with($account, 'card:');
        $cardId = $isCardValue ? (int) substr($account, 5) : null;

        if ($type === 'card' || $isCardValue) {
            if ($cardId) {
                $query->where(fn ($q) => $q->where('credit_card_id', $cardId)->orWhere('additional_card_id', $cardId));
            } elseif ($type === 'card') {
                $query->whereNotNull('credit_card_id');
            }

            return;
        }

        if ($type !== '' && in_array($type, BankAccount::TYPE_ORDER, true)) {
            if ($account !== '' && ctype_digit($account)) {
                $query->where('bank_account_id', (int) $account);
            } else {
                $ids = BankAccount::where('type', $type)->pluck('id');
                $query->whereIn('bank_account_id', $ids->isEmpty() ? [0] : $ids);
            }

            return;
        }

        if ($cardId) {
            $query->where(fn ($q) => $q->where('credit_card_id', $cardId)->orWhere('additional_card_id', $cardId));
        } elseif ($account !== '' && ctype_digit($account)) {
            $query->where('bank_account_id', (int) $account);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'category', 'account', 'accountType', 'status', 'type', 'testes', 'historico', 'vinculo']);
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

    /**
     * Lançamento rápido: "+ Lançar" (topo), botão "+" (celular), atalhos do painel e teclas nr/nd/nt
     * disparam este evento. Abre o MESMO formulário/validação do "Novo lançamento".
     */
    #[\Livewire\Attributes\On('quick-entry')]
    public function quickEntry(string $type = 'expense'): void
    {
        $this->create(in_array($type, ['expense', 'income', 'card_expense', 'transfer'], true) ? $type : 'expense');
        $this->dispatch('focus-transaction-amount');
    }

    /** Abas Despesa/Receita/Transferência (só em lançamento novo): troca o tipo mantendo valor, data, descrição, conta e observações. */
    public function switchType(string $type): void
    {
        if ($this->editingId || ! in_array($type, ['expense', 'income', 'card_expense', 'transfer'], true)) {
            return;
        }
        $isCard = $type === 'card_expense';
        $newType = $isCard ? 'expense' : $type;
        if ($newType !== $this->formType || $isCard !== $this->formIsCard) {
            $this->reset(['formCategoryId', 'formAllocationEnabled', 'formAllocationRows', 'formAllocationMode']);
        }
        if ($newType !== 'transfer') {
            $this->reset('formTransferToId');
        }
        if (! $isCard) {
            $this->reset(['formCreditCardId', 'formInvoiceMonth', 'formIsCardRefund']);
            $this->formInstallments = 1;
        }
        if ($newType !== 'income') {
            $this->reset('formDestinateAfterSave');
        }
        $this->formIsCard = $isCard;
        $this->formType = $newType;
        $this->resetValidation();
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
        $this->editingProcessReceipt = $transaction->processReceipt()->exists();
        $this->formDestinateAfterSave = $this->editingProcessReceipt;
        $this->editingHasAllocations = $transaction->allocations_count > 0;
        $this->formAllocationEnabled = $this->editingHasAllocations;
        $this->formAllocationRows = $transaction->allocations->map(fn ($row) => [
            'key' => (string) Str::uuid(), 'category_id' => $row->category_id, 'amount' => (string) $row->amount,
        ])->all();
        $this->formLinkProcess = (string) $transaction->link_process;
        $this->formLinkWritId = $transaction->link_writ_id;
        $this->formLinkBrokerId = $transaction->link_broker_id;
        $this->formLinkContactId = $transaction->link_contact_id;
        $this->showOptionalLinks = trim((string) $transaction->link_process) !== ''
            || $transaction->link_writ_id || $transaction->link_broker_id || $transaction->link_contact_id
            || \App\Domains\Banking\Models\TransactionAttachment::where('transaction_id', $transaction->id)->exists();
        $this->formType = $transaction->type;
        $this->formDate = $transaction->date->format('Y-m-d');
        $this->formAmount = (string) abs((float) $transaction->amount);
        $this->formDescription = $transaction->description;
        $this->formNotes = (string) $transaction->notes;
        $this->formStatus = $transaction->status;
        $this->formCategoryId = $transaction->category_id;
        $this->formBankAccountId = $transaction->bank_account_id;
        $this->formCreditCardId = $transaction->additional_card_id ?? $transaction->credit_card_id;
        if ($transaction->type === 'transfer') {
            // Transferência: carrega os DOIS lados (origem = saída, destino = entrada), seja qual for a linha clicada.
            $pair = $transaction->related_transaction_id ? Transaction::find($transaction->related_transaction_id) : Transaction::where('related_transaction_id', $transaction->id)->where('type', 'transfer')->first();
            if ((float) $transaction->amount < 0) {
                $this->formBankAccountId = $transaction->bank_account_id;
                $this->formTransferToId = $pair?->bank_account_id;
            } else {
                $this->formTransferToId = $transaction->bank_account_id;
                $this->formBankAccountId = $pair?->bank_account_id;
            }
        }
        $this->formIsCard = $transaction->type === 'expense' && $transaction->credit_card_id !== null;
        $this->formIsCardRefund = $this->formIsCard && (float) $transaction->amount < 0;
        $this->formInvoiceMonth = $transaction->invoice?->reference_month ?? '';
        if ($this->formIsCard && $this->formInvoiceMonth === '') {
            $this->suggestInvoiceMonth();
        }
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function toggleOptionalLinks(): void
    {
        $this->showOptionalLinks = ! $this->showOptionalLinks;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    /** Atalho Etapa 6: abre Valores a receber sem duplicar a saída. */
    public function payForSomeone(): void
    {
        if ($this->formIsCardRefund || (! $this->formIsCard && $this->formType !== 'expense')) {
            $this->addError('transaction', 'Paguei por alguém vale para despesa bancária ou despesa no cartão.');

            return;
        }

        if ($this->editingId) {
            $transaction = Transaction::findOrFail($this->editingId);
            if ($transaction->type !== 'expense' || (! $transaction->bank_account_id && ! $transaction->credit_card_id)) {
                $this->addError('transaction', 'Selecione uma despesa bancária ou compra no cartão.');

                return;
            }
            $this->redirect(route('banking.receivables.index', ['transaction' => $transaction->id]), navigate: true);

            return;
        }

        $this->validate([
            'formDate' => 'required|date',
            'formAmount' => 'required|numeric|min:0.01',
            'formDescription' => 'required|string|max:200',
            'formBankAccountId' => [Rule::requiredIf(! $this->formIsCard), 'nullable', 'exists:bank_accounts,id'],
            'formCreditCardId' => [Rule::requiredIf($this->formIsCard), 'nullable', Rule::exists('credit_cards', 'id')->whereNull('deleted_at')->where('status', true)],
            'formInstallments' => 'nullable|integer|min:1|max:36',
            'formNotes' => 'nullable|string|max:5000',
        ]);

        $params = [
            'criar' => '1',
            'origin' => $this->formIsCard ? 'card' : 'bank',
            'date' => \Illuminate\Support\Carbon::parse($this->formDate)->toDateString(),
            'amount' => number_format((float) $this->formAmount, 2, '.', ''),
            'description' => $this->formDescription,
            'installments' => max(1, (int) $this->formInstallments),
        ];
        if ($this->formNotes !== '') {
            $params['notes'] = $this->formNotes;
        }
        if ($this->formIsCard) {
            $params['credit_card_id'] = $this->formCreditCardId;
        } else {
            $params['bank_account_id'] = $this->formBankAccountId;
        }
        if ($this->formLinkContactId) {
            $params['contact'] = $this->formLinkContactId;
        }

        $this->redirect(route('banking.receivables.index', $params), navigate: true);
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

    public function saveAndCreateAnother(TransactionService $service, TransferService $transfer, InstallmentService $installment, TransactionAllocationService $allocations): void
    {
        if ($this->editingId) {
            $this->saveTransaction($service, $transfer, $installment, $allocations);

            return;
        }

        $this->createAnotherAfterSave = true;
        $this->saveTransaction($service, $transfer, $installment, $allocations);
    }

    public function saveTransaction(TransactionService $service, TransferService $transfer, InstallmentService $installment, TransactionAllocationService $allocations): void
    {
        $createAnother = $this->createAnotherAfterSave;
        $this->createAnotherAfterSave = false;
        $this->formSuccessMessage = null;

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

        if ($this->editingProcessReceipt) { $this->formDestinateAfterSave = true; }
        if ($this->formType === 'income' && $this->formDestinateAfterSave) {
            $this->formAllocationEnabled = false;
            $this->formCategoryId = null;
        }

        // Categoria obrigatória em Receita e Despesa, exceto rateio (categoria por linha), recebimento de processo e estorno de cartão.
        $categoryRequired = in_array($this->formType, ['income', 'expense'], true) && ! $this->formAllocationEnabled
            && ! ($this->formType === 'income' && $this->formDestinateAfterSave) && ! $this->formIsCardRefund
            && ! ($this->editingId && Transaction::whereKey($this->editingId)->whereHas('destinations')->exists());

        $data = $this->validate([
            'formType' => 'required|in:income,expense,transfer',
            'formDate' => 'required|date',
            'formAmount' => 'required|numeric|min:0.01',
            'formDescription' => [Rule::requiredIf($this->formType !== 'transfer'), 'nullable', 'string', 'max:200'],
            'formNotes' => 'nullable|string',
            'formStatus' => $this->formType === 'income' && $this->formDestinateAfterSave ? 'required|in:settled' : 'required|in:pending,settled',
            'formCategoryId' => [Rule::requiredIf($categoryRequired), 'nullable', Rule::exists('categories', 'id')->where('is_system', 0)->when(
                in_array($this->formType, ['income', 'expense'], true),
                fn ($rule) => $rule->where('type', $this->formType),
            )],
            'formBankAccountId' => [Rule::requiredIf($this->formType === 'income' && $this->formDestinateAfterSave), 'nullable', 'exists:bank_accounts,id'],
            'formCreditCardId' => [Rule::requiredIf($this->formIsCard), 'nullable', Rule::exists('credit_cards', 'id')->whereNull('deleted_at')->when(! $this->editingId, fn ($rule) => $rule->where('status', true))],
            'formInvoiceMonth' => [Rule::requiredIf($this->formIsCard), 'nullable', 'date_format:Y-m'],
            'formTransferToId' => 'nullable|exists:bank_accounts,id|different:formBankAccountId',
            'formInstallments' => 'required|integer|min:1|max:36',
            'formAllocationMode' => 'required|in:amount,percentage',
            'formLinkProcess' => 'nullable|string|max:120',
            'formLinkWritId' => ['nullable', 'integer', Rule::exists('writs', 'id')->whereNull('deleted_at')],
            'formLinkBrokerId' => ['nullable', 'integer', Rule::exists('brokers', 'id')->whereNull('deleted_at')],
            'formLinkContactId' => ['nullable', 'integer', Rule::exists('contacts', 'id')->whereNull('deleted_at')],
            'formAttachment' => ['nullable', 'file', 'mimes:'.\App\Domains\Banking\Models\TransactionAttachment::MIMES, 'max:'.\App\Domains\Banking\Models\TransactionAttachment::MAX_KB],
        ], [
            'formAttachment.mimes' => 'O comprovante deve ser PDF, JPG ou PNG.',
            'formAttachment.max' => 'O comprovante deve ter no máximo 10 MB.','formCategoryId.required' => 'Selecione a categoria. Ela é obrigatória em receitas e despesas.']);

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
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($service, $transfer, $installment, $allocations, $data, $allocationRows) {
                $saved = [];
                if ($this->editingId) {
                    $transaction = Transaction::withCount('allocations')->findOrFail($this->editingId);

                    if ($transaction->allocations_count > 0 && $data['formType'] !== $transaction->type) {
                        throw new \DomainException('Remova o rateio antes de alterar o tipo do lançamento.');
                    }

                    if ($data['formType'] === 'transfer') {
                        // Edição/conversão em transferência: atualiza os dois lados (contas antigas e novas) num passo só.
                        $saved = [$transfer->update(
                            $transaction,
                            $data['formBankAccountId'] ? BankAccount::findOrFail($data['formBankAccountId']) : null,
                            $data['formTransferToId'] ? BankAccount::findOrFail($data['formTransferToId']) : null,
                            (float) $data['formAmount'],
                            $data['formDate'],
                            $data['formNotes'] ?: null,
                            $data['formStatus'],
                        )];

                        $message = 'Transferência atualizada.';
                    } else {
                    if ($transaction->type === 'transfer') {
                        // Transferência virou receita/despesa: o outro lado sai, sem órfão.
                        $transfer->detach($transaction);
                        $transaction->refresh()->loadCount('allocations');
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
                        'additional_card_id' => null,
                    ];
                    if ($this->formIsCard) {
                        $picked = CreditCard::findOrFail($data['formCreditCardId']);
                        if ($picked->isAdditional() && $picked->parent) {
                            $updates['credit_card_id'] = $picked->parent_card_id;
                            $updates['additional_card_id'] = $picked->id;
                        }
                        $updates['credit_card_invoice_id'] = app(InvoiceService::class)->findOrCreateForReference(
                            $picked->invoiceCard(),
                            Carbon::createFromFormat('!Y-m', $data['formInvoiceMonth']),
                        )->id;
                    }
                    $saved = [$service->update($transaction, $updates)];
                    $message = 'Lançamento atualizado.';
                    }
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
                $links = [
                    'link_process' => trim((string) ($data['formLinkProcess'] ?? '')) ?: null,
                    'link_writ_id' => $data['formLinkWritId'] ?? null,
                    'link_broker_id' => $data['formLinkBrokerId'] ?? null,
                    'link_contact_id' => $data['formLinkContactId'] ?? null,
                ];
                foreach ($saved as $savedTransaction) {
                    // Vínculos são só referência: não alteram valor, saldo ou categoria.
                    $savedTransaction->forceFill($links)->saveQuietly();
                }
                if ($this->formAttachment && ($saved[0] ?? null)) {
                    app(\App\Domains\Banking\Services\TransactionAttachmentService::class)->store($saved[0], $this->formAttachment);
                }
                return ['message' => $message, 'receipt_id' => ($saved[0] ?? null)?->id];
            });
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addError('transaction', $e->getMessage());
            return;
        }
        if ($result === null) {
            return;
        }

        $destinate = $this->formType === 'income' && $this->formDestinateAfterSave;
        $reopenType = null;
        $keepCard = null;
        if ($createAnother && ! $destinate) {
            $reopenType = $this->formIsCard ? 'card_expense' : $this->formType;
            // Lançamento em série no cartão: mantém cartão, fatura e data para a próxima compra.
            if ($this->formIsCard) {
                $keepCard = ['card' => $this->formCreditCardId, 'invoice' => $this->formInvoiceMonth, 'date' => $this->formDate];
            }
        }

        // "Salvar e lançar outro": mantém o tipo e a conta (cartão, fatura e data no cartão).
        $keepAccount = $reopenType !== null && ! $keepCard ? $this->formBankAccountId : null;

        $this->resetForm();
        $this->resetPage();
        $this->dispatch('transaction-saved');
        if ($this->quick && $destinate) {
            // Destinar a receita acontece na tela de Lançamentos (mesmo fluxo de sempre).
            session()->flash('status', $result['message']);
            $this->redirect(route('banking.transactions.index', ['distribuir' => $result['receipt_id']]));

            return;
        }
        if ($this->quick) {
            $this->dispatch('toast', type: 'success', message: $result['message']);
        } else {
            session()->flash('status', $result['message']);
        }
        if ($destinate) {
            $this->distributingId = $result['receipt_id'];
        } elseif ($reopenType !== null) {
            $this->create($reopenType);
            if ($keepAccount) {
                $this->formBankAccountId = $keepAccount;
            }
            if ($keepCard) {
                $this->formDate = $keepCard['date'];
                $this->formCreditCardId = $keepCard['card'];
                $this->formInvoiceMonth = $keepCard['invoice'];
            }
            $this->formSuccessMessage = $result['message'];
            $this->dispatch('focus-transaction-amount');
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'showFormModal', 'editingId', 'formAmount', 'formDescription', 'formNotes',
            'formCategoryId', 'formBankAccountId', 'formCreditCardId', 'formTransferToId', 'editingHasAllocations', 'formIsCard', 'formIsCardRefund', 'formInvoiceMonth', 'formAllocationEnabled', 'formAllocationRows', 'formAllocationMode', 'formDestinateAfterSave', 'editingProcessReceipt',
            'formLinkProcess', 'formLinkWritId', 'formLinkBrokerId', 'formLinkContactId', 'linkSearch', 'formAttachment', 'showOptionalLinks',
            'createAnotherAfterSave', 'formSuccessMessage',
        ]);
        $this->formType = 'expense';
        $this->formDate = now('America/Sao_Paulo')->format('Y-m-d');
        $this->formStatus = 'settled';
        $this->formInstallments = 1;
        $this->resetValidation();
    }

    public function deleteAttachment(int $id): void
    {
        $attachment = \App\Domains\Banking\Models\TransactionAttachment::findOrFail($id);
        app(\App\Domains\Banking\Services\TransactionAttachmentService::class)->delete($attachment);
        session()->flash('status', 'Comprovante removido.');
    }

    public function delete(int $id): void
    {
        $transaction = Transaction::with('destinationPayment')->findOrFail($id);
        if ($transaction->isReadOnly() && ! ($transaction->destinationPayment && $transaction->source_type === \App\Domains\Banking\Models\ReceiptDestinationPayment::class)) {
            session()->flash('error', 'Lançamento gerado por outro módulo é somente leitura.');
            return;
        }
        $this->resetValidation('deletion');
        $this->deletingId = $id;
    }

    public function cancelDeletion(): void
    {
        $this->deletingId = null;
        $this->resetValidation('deletion');
    }

    public function confirmDeletion(TransactionService $service): void
    {
        if ($this->deletingId === null) {
            return;
        }
        try {
            $service->deleteWithDestinations(Transaction::findOrFail($this->deletingId));
            if ($this->distributingId === $this->deletingId) {
                $this->distributingId = null;
            }
            $this->cancelDeletion();
            $this->resetPage();
            session()->flash('status', 'Lançamento excluído.');
        } catch (\DomainException $e) {
            $this->addError('deletion', $e->getMessage());
        }
    }

    /** Opções dos vínculos, filtradas pela busca (nome, cedente ou número do processo). */
    private function linkOptions(): array
    {
        $term = trim($this->linkSearch);
        $like = '%'.$term.'%';
        $search = function ($model, array $columns, ?int $selected, int $limit, ?string $activeColumn = null) use ($term, $like) {
            return $model::query()
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => collect($columns)->each(fn ($c) => $q->orWhere($c, 'like', $like))))
                ->when($term === '' && $activeColumn, fn ($q) => $q->where($activeColumn, true))
                ->when($selected && ($term !== '' || $activeColumn), fn ($q) => $q->orWhere('id', $selected))
                ->orderBy($columns[0])->limit($limit)->get();
        };

        return [
            // Requisitório: busca e exibe o cedente pelo vínculo com Contatos.
            'writs' => \App\Domains\Writs\Models\Writ::query()->with('assignors.contact:id,name')
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('assignor_name', 'like', $like)->orWhere('process_number', 'like', $like)->orWhereHas('assignors.contact', fn ($c) => $c->where('name', 'like', $like))))
                ->when($this->formLinkWritId && $term !== '', fn ($q) => $q->orWhere('id', $this->formLinkWritId))
                ->orderBy('process_number')->limit(100)->get(),
            'brokers' => $search(\App\Domains\Brokers\Models\Broker::class, ['name'], $this->formLinkBrokerId, 100, 'status'),
            'contacts' => $search(\App\Domains\Contacts\Models\Contact::class, ['name'], $this->formLinkContactId, 150, 'status'),
        ];
    }

    /** Somente vínculos em uso aparecem no filtro. */
    private function linkFilterOptions(): array
    {
        $options = [];
        foreach (\App\Domains\Writs\Models\Writ::whereIn('id', Transaction::whereNotNull('link_writ_id')->select('link_writ_id'))->orWhereIn('id', \App\Domains\Banking\Models\ProcessReceipt::whereNotNull('writ_id')->select('writ_id'))->with('assignors.contact:id,name')->get()->sortBy(fn ($w) => $w->beneficiaryName()) as $w) {
            $options['writ:'.$w->id] = 'Requisitório: '.$w->beneficiaryName().' · '.($w->process_number ?: '#'.$w->id);
        }
        foreach (\App\Domains\Brokers\Models\Broker::withTrashed()->whereIn('id', Transaction::whereNotNull('link_broker_id')->select('link_broker_id'))->orWhereIn('id', \App\Domains\Banking\Models\ProcessReceiptBroker::select('broker_id'))->orderBy('name')->get() as $b) {
            $options['broker:'.$b->id] = 'Corretor: '.$b->name;
        }
        foreach (\App\Domains\Contacts\Models\Contact::whereIn('id', Transaction::whereNotNull('link_contact_id')->select('link_contact_id'))->orderBy('name')->get() as $c) {
            $options['contact:'.$c->id] = 'Pessoa: '.$c->name;
        }

        return $options;
    }

    /**
     * Opções do dropdown "Conta ou cartão" conforme o tipo selecionado.
     *
     * @return array{groups: list<array{label: string, options: list<array{value: string, label: string}}>}, placeholder: string}
     */
    private function filterAccountOptions(): array
    {
        $type = $this->accountType;
        if ($type === 'card') {
            $cards = CreditCard::orderBy('name')->get();

            return [
                'placeholder' => 'Todos os cartões',
                'groups' => [[
                    'label' => 'Cartões',
                    'options' => $cards->map(fn (CreditCard $c) => [
                        'value' => 'card:'.$c->id,
                        'label' => $c->name.($c->brand ? ' · '.$c->brand : ''),
                    ])->all(),
                ]],
            ];
        }

        if ($type !== '' && in_array($type, BankAccount::TYPE_ORDER, true)) {
            $accounts = BankAccount::where('type', $type)->orderBy('name')->get();
            $label = (new BankAccount(['type' => $type]))->typeShortLabel();

            return [
                'placeholder' => 'Todas · '.$label,
                'groups' => [[
                    'label' => $label,
                    'options' => $accounts->map(fn (BankAccount $a) => [
                        'value' => (string) $a->id,
                        'label' => $a->name,
                    ])->all(),
                ]],
            ];
        }

        $accounts = BankAccount::orderBy('name')->get()->groupBy('type');
        $groups = [];
        foreach (BankAccount::TYPE_ORDER as $t) {
            if (! $accounts->has($t) || $accounts[$t]->isEmpty()) {
                continue;
            }
            $groups[] = [
                'label' => (new BankAccount(['type' => $t]))->typeShortLabel(),
                'options' => $accounts[$t]->map(fn (BankAccount $a) => [
                    'value' => (string) $a->id,
                    'label' => $a->name,
                ])->values()->all(),
            ];
        }
        $cards = CreditCard::orderBy('name')->get();
        if ($cards->isNotEmpty()) {
            $groups[] = [
                'label' => 'Cartões',
                'options' => $cards->map(fn (CreditCard $c) => [
                    'value' => 'card:'.$c->id,
                    'label' => $c->name.($c->brand ? ' · '.$c->brand : ''),
                ])->all(),
            ];
        }

        return [
            'placeholder' => 'Todas (contas e cartões)',
            'groups' => $groups,
        ];
    }

    /** Dados que o modal do lançamento usa (compartilhado entre a tela de Lançamentos e o lançamento rápido). */
    private function formViewData(): array
    {
        return [
            'activeCategories' => Category::active()->selectable()->orderBy('name')->get(),
            'formIsHistorical' => ! $this->formIsCard && $this->formBankAccountId && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->formDate)
                && (bool) BankAccount::find($this->formBankAccountId)?->isHistoricalDate($this->formDate),
            'activeAccounts' => BankAccount::active()->orderBy('name')->get(),
            'linkOptions' => $this->linkOptions(),
            'cards' => CreditCard::where(fn ($q) => $q->where('status', true)->when($this->editingId && $this->formCreditCardId, fn ($q) => $q->orWhere('id', $this->formCreditCardId)))->orderBy('name')->get(),
        ];
    }

    public function with(): array
    {
        if ($this->quick) {
            return $this->showFormModal ? $this->formViewData() : [];
        }
        if ($this->preview === 'ofx') {
            return [];
        }

        $q = Transaction::query();
        if ($this->testes === '1') {
            $q->withTests();
        }
        if ($this->historico === 'ocultar') {
            $q->notHistorical();
        } elseif ($this->historico === 'somente') {
            $q->whereHas('bankAccount', fn ($a) => $a->whereNotNull('initial_balance_date')->whereColumn('bank_accounts.initial_balance_date', '>', 'transactions.date'));
        }
        $q->with(['receivableInstallments.receivable', 'receivableSettlements', 'category', 'allocations.category', 'bankAccount', 'creditCard', 'distributionPart', 'related.distributionPart', 'processReceipt.category', 'destinations.recoveries', 'destinations.commission', 'destinations.payments.transaction', 'destinationPayment.destination', 'writLinks.destination', 'linkWrit.assignors.contact', 'linkBroker', 'linkContact', 'attachments', 'processReceipt.brokerShares.broker', 'processReceipt.writ']);
        if (preg_match('/^(writ|broker|contact):(\d+)$/', $this->vinculo, $m)) {
            $column = 'link_'.$m[1].'_id';
            $q->where(fn ($q) => $q->where($column, (int) $m[2])
                ->when($m[1] === 'writ', fn ($q) => $q->orWhereHas('processReceipt', fn ($p) => $p->where('writ_id', (int) $m[2])))
                ->when($m[1] === 'broker', fn ($q) => $q->orWhereHas('processReceipt.brokerShares', fn ($p) => $p->where('broker_id', (int) $m[2]))));
        } elseif (str_starts_with($this->vinculo, 'process:') && trim(substr($this->vinculo, 8)) !== '') {
            $term = trim(substr($this->vinculo, 8));
            $q->where(fn ($q) => $q->where('link_process', 'like', '%'.$term.'%')->orWhereHas('processReceipt', fn ($p) => $p->where('process_ref', 'like', '%'.$term.'%')));
        } elseif ($this->vinculo === 'com') {
            $q->where(fn ($q) => $q->whereNotNull('link_process')->orWhereNotNull('link_writ_id')->orWhereNotNull('link_broker_id')->orWhereNotNull('link_contact_id'));
        }

        if ($this->from) {
            $q->where('date', '>=', $this->from);
        }
        if ($this->to) {
            $q->where('date', '<=', $this->to);
        }
        if ($this->category) {
            $q->where(function ($query) {
                $query->where(fn ($q) => $q->whereDoesntHave('processReceipt')->where('category_id', $this->category))
                    ->orWhereHas('processReceipt', fn ($q) => $q->where('category_id', $this->category))
                    ->orWhere(fn ($q) => $q->whereDoesntHave('processReceipt')->whereHas('allocations', fn ($allocations) => $allocations->where('category_id', $this->category)));
            });
        }
        $this->applyAccountOrCardFilter($q);
        if ($this->status) {
            $q->where('status', $this->status);
        }
        if ($this->type) {
            $q->where('type', $this->type);
        }
        if ($this->titular !== '') {
            $ids = CreditCard::idsForHolderKey($this->titular) ?: [0];
            // Compra de adicional conta para o titular do adicional; as demais, para o titular do cartão.
            $q->where(fn ($w) => $w->whereIn('additional_card_id', $ids)->orWhere(fn ($x) => $x->whereNull('additional_card_id')->whereIn('credit_card_id', $ids)));
        }

        $filterAccountOptions = $this->filterAccountOptions();

        return [
            'deletingTransaction' => $this->deletingId ? Transaction::withCount('destinations')->with('destinationPayment')->find($this->deletingId) : null,
            'transactions' => $q->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'categories' => Category::orderBy('name')->get(),
            'accounts' => BankAccount::orderBy('name')->get(),
            'filterAccountOptions' => $filterAccountOptions,
            'linkFilterOptions' => $this->linkFilterOptions(),
            'holderOptions' => CreditCard::holderFilterOptions(),
        ] + $this->formViewData();
    }
}; ?>

@unless ($quick)
<x-slot name="header">{{ $preview === 'ofx' ? 'Prévia da importação OFX' : 'Financeiro' }}</x-slot>
@endunless

<div @if ($quick) data-quick-entry @else class="flex flex-col gap-space-5" @endif>
@if ($quick)
    {{-- Lançamento rápido global: só o modal abaixo. --}}
@elseif ($preview === 'ofx')
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
                <x-jr.button size="sm" :href="route('banking.transactions.statement-import')" data-statement-import-link title="OFX, CSV, XLSX, PDF ou foto: o formato é detectado sozinho">
                    <span class="material-icons-outlined text-[18px]" aria-hidden="true">upload_file</span>
                    Importar extrato
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
                <x-banking.category-picker :categories="$categories" :selected-id="$category" model="category" id="transaction-category-filter" placeholder="Todas" clear-value="" />
            </div>
            <div class="flex flex-col gap-space-1 min-w-0">
                <label for="transaction-account-filter" class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Conta ou cartão</label>
                <select id="transaction-account-filter" wire:model.live="account" data-test="account-or-card-filter" class="h-[48px] w-full px-space-4 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary focus:border-cryptex-brand-400 focus:outline-none transition-colors">
                    <option value="">{{ $filterAccountOptions['placeholder'] }}</option>
                    @foreach ($filterAccountOptions['groups'] as $group)
                        @if (count($group['options']) > 0)
                            <optgroup label="{{ $group['label'] }}">
                                @foreach ($group['options'] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endif
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
        <div class="mt-space-3 flex flex-col gap-space-2" data-test="account-type-filter">
            <span class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Tipo de conta ou cartão</span>
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Tipo de conta ou cartão">
                @foreach ($this->accountTypeFilterOptions() as $value => $label)
                    <button type="button"
                        wire:click="$set('accountType', '{{ $value }}')"
                        aria-pressed="{{ $accountType === $value ? 'true' : 'false' }}"
                        data-test="account-type-{{ $value === '' ? 'all' : $value }}"
                        class="rounded-pill border px-3 py-1.5 text-xs font-semibold transition-colors whitespace-nowrap {{ $accountType === $value ? 'bg-primary-500 text-white border-primary-500' : 'bg-mono-white text-mono-600 border-mono-200 hover:border-primary-300' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
        <div class="mt-space-3 flex flex-wrap items-center gap-space-4 text-fs-12">
            <label class="flex items-center gap-2 text-cryptex-text-secondary">
                Históricos
                <select wire:model.live="historico" class="h-[32px] px-2 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-12 text-cryptex-text-primary">
                    <option value="">Mostrar</option>
                    <option value="ocultar">Ocultar</option>
                    <option value="somente">Somente históricos</option>
                </select>
                <select wire:model.live="titular" aria-label="Gastos por titular do cartão" class="ml-2 h-[32px] px-2 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-12 text-cryptex-text-primary">
                    <option value="">Todos os titulares</option>
                    @foreach ($holderOptions as $value => $label)<option value="{{ $value }}">Cartão: {{ $label }}</option>@endforeach
                </select>
                <select wire:model.live="vinculo" aria-label="Filtrar por vínculo" class="ml-2 h-[32px] px-2 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-12 text-cryptex-text-primary">
                    <option value="">Todos os vínculos</option>
                    <option value="com">Somente com vínculo</option>
                    @foreach ($linkFilterOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    @if (str_starts_with($vinculo, 'process:'))<option value="{{ $vinculo }}">Processo: {{ substr($vinculo, 8) }}</option>@endif
                </select>
            </label>
            <label class="flex items-center gap-2 text-cryptex-text-secondary">
                <input type="checkbox" wire:model.live="testes" value="1" true-value="1" false-value=""> Mostrar lançamentos de teste
            </label>
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
                            {{-- Celular: a coluna Valor fica fora da tela (tabela rola), então o valor colorido aparece também aqui. --}}
                            <span class="sm:hidden block mt-1 font-mono font-semibold [font-variant-numeric:tabular-nums]" data-mobile-amount><x-fx.amount :type="$t->type" :amount="$t->amount" /></span>
                            @if ($t->isInstallment())
                                <span class="text-fs-12 text-cryptex-text-tertiary ml-1 font-mono">{{ $t->installment_number }}/{{ $t->installment_total }}</span>
                            @endif
                            @if ($t->isReadOnly())
                                <x-fx.badge variant="neutral" class="ml-space-2">{{ match ($t->source_type) { \App\Domains\Banking\Models\ReceiptDestinationPayment::class => 'Repasse vinculado', \App\Domains\Banking\Models\ReceiptDestination::class => 'Valor a repassar', default => 'origem: '.class_basename($t->source_type) } }}</x-fx.badge>
                            @endif
                            @if ($t->writLinks->isNotEmpty())
                                <p class="mt-1 text-xs text-cryptex-text-secondary">R$ {{ number_format((float) $t->writLinks->sum('writ_amount'), 2, ',', '.') }} vinculados a recebimentos de processo. Valor desta linha: entrada restante, para contar o dinheiro uma única vez.</p>
                            @endif
                            @if ($t->isHistorical())
                                <x-fx.badge variant="neutral" class="ml-space-2" title="Anterior ao saldo inicial da conta: registra no módulo, mas não altera o saldo nem o resultado">Histórico</x-fx.badge>
                            @endif
                            @if ($t->is_test)
                                <x-fx.badge variant="warning" class="ml-space-2">Teste</x-fx.badge>
                            @endif
                            @if ($t->hasLinks())
                                <p class="mt-1 text-xs text-cryptex-text-secondary">
                                    @if ($t->link_process)<span class="mr-2">Processo: <a href="{{ route('banking.transactions.index', ['vinculo' => 'process:'.$t->link_process]) }}" class="text-primary-600">{{ $t->link_process }}</a></span>@endif
                                    @if ($t->linkWrit)<span class="mr-2">Requisitório: <a href="{{ route('banking.transactions.index', ['vinculo' => 'writ:'.$t->link_writ_id]) }}" class="text-primary-600">{{ $t->linkWrit->beneficiaryName() }} · {{ $t->linkWrit->process_number ?: '#'.$t->link_writ_id }}</a></span>@endif
                                    @if ($t->linkBroker)<span class="mr-2">Corretor: <a href="{{ route('banking.transactions.index', ['vinculo' => 'broker:'.$t->link_broker_id]) }}" class="text-primary-600">{{ $t->linkBroker->name }}</a></span>@endif
                                    @if ($t->linkContact)<span class="mr-2">Pessoa: <a href="{{ route('banking.transactions.index', ['vinculo' => 'contact:'.$t->link_contact_id]) }}" class="text-primary-600">{{ $t->linkContact->name }}</a></span>@endif
                                </p>
                            @endif
                            @foreach ($t->attachments as $file)
                                <a href="{{ route('banking.transactions.attachments.download', $file) }}" class="mt-1 mr-2 inline-flex items-center gap-1 text-xs text-primary-600" title="Baixar comprovante"><span class="material-icons-outlined text-[14px]" aria-hidden="true">attach_file</span>{{ $file->original_name }}</a>
                            @endforeach
                            @if ($t->ofx_fitid)
                                <x-fx.badge variant="neutral" class="ml-space-2">OFX</x-fx.badge>
                            @endif
                            @if ($t->allocations->isNotEmpty() && ! $t->processReceipt)
                                <x-fx.badge variant="neutral" class="ml-space-2">rateado</x-fx.badge>
                            @endif
                            @if ($t->status === 'pending')
                                <x-fx.badge variant="warning" class="ml-space-2">pendente</x-fx.badge>
                            @endif
                            @if ($t->destinations->isNotEmpty())
                                <x-fx.badge variant="neutral">Recebimento de processo</x-fx.badge>
                                <div class="mt-2 space-y-1 text-xs text-cryptex-text-secondary">
                                    <div>Minha receita: <strong>R$ {{ number_format($t->destinations->where('kind', 'own')->sum('amount'), 2, ',', '.') }}</strong></div>
                                    @php $writOwn = $t->destinations->sum(fn ($part) => (float) $part->payments->sum('writ_amount')); @endphp
                                    @if ($writOwn > 0)<div>Requisitórios adquiridos: <strong>R$ {{ number_format($writOwn, 2, ',', '.') }}</strong></div>@endif
                                    @if ($t->processReceipt?->writ)<div>Requisitório baixado: <strong>{{ $t->processReceipt->writ->process_number ?: '#'.$t->processReceipt->writ_id }}</strong> · {{ $t->processReceipt->writ->stageLabel() }}@php $realDays = app(\App\Domains\Writs\Services\WritProfitabilityCalculator::class)->realized($t->processReceipt->writ)['days_elapsed']; @endphp @if ($realDays !== null) · prazo real {{ (int) $realDays }} dias @endif</div>@endif
                                    @if (($t->processReceipt?->brokerShares->count() ?? 0) > 0)<div>Corretores: @foreach ($t->processReceipt->brokerShares as $share){{ $share->broker?->name }} R$ {{ number_format((float) $share->calculated_amount, 2, ',', '.') }} ({{ $share->mode === 'percent' ? number_format((float) $share->rate_or_amount, 2, ',', '.').'% s/ '.($share->base === 'gross' ? 'bruto' : 'honorários') : 'fixo' }})@if (! $loop->last); @endif @endforeach</div>@endif
                                    <details><summary class="cursor-pointer text-primary-600">Ver beneficiários</summary>
                                        @foreach ($t->destinations as $part)<p class="mt-1">{{ $part->beneficiary }} · R$ {{ number_format((float) $part->amount, 2, ',', '.') }}</p>@endforeach
                                    </details>
                                </div>
                            @endif
                        </td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">
                            @if ($t->processReceipt)
                                {{ $t->processReceipt->category?->name ?? 'Sem categoria' }}<span class="block text-xs">Somente minha receita</span>
                            @elseif ($t->allocations->isNotEmpty())
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
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums]">
                            <x-fx.amount :type="$t->type" :amount="$t->amount" />
                        </td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            @if ($t->source_type === \App\Domains\Banking\Models\ReceiptDestination::class)
                                @php $distributionPart = $t->distributionPart ?? $t->related?->distributionPart; @endphp
                                @if ($distributionPart)<button type="button" wire:click="openDistribution({{ $distributionPart->receipt_id }})" class="mr-3 text-fs-12 font-medium text-primary-600">Ver recebimento</button>@endif
                            @endif
                            @foreach ($t->writLinks->unique(fn ($link) => $link->destination->receipt_id) as $link)
                                <button type="button" wire:click="openDistribution({{ $link->destination->receipt_id }})" class="mr-3 text-fs-12 font-medium text-primary-600">Ver recebimento vinculado</button>
                            @endforeach
                            @if ($t->destinationPayment?->destination)
                                <button type="button" wire:click="openDistribution({{ $t->destinationPayment->destination->receipt_id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">Ver recebimento</button>
                            @endif
                            @if ($t->receivableInstallments->isNotEmpty())
                                <a href="{{ route('banking.receivables.index', ['contact' => $t->receivableInstallments->first()->receivable->contact_id]) }}" class="mr-3 text-fs-12 font-medium text-primary-500">Valores a receber</a>
                            @endif
                            @if ($t->receivableSettlements->isNotEmpty())
                                <a href="{{ route('banking.receivables.index', ['status' => '']) }}" class="mr-3 text-fs-12 font-medium text-primary-500">Devolução de adiantamento</a>
                            @endif
                            @unless ($t->isReadOnly())
                                @if ($t->type === 'expense' && $t->status === 'settled' && (float) $t->amount > 0 && ! $t->destinationPayment)
                                    <a href="{{ route('banking.receivables.index', ['transaction' => $t->id]) }}" class="mr-3 text-fs-12 font-medium text-primary-500">Paguei por alguém</a>
                                @endif
                                @if ($t->type === 'income' && $t->status === 'settled' && $t->bank_account_id)
                                    <button type="button" wire:click="openDistribution({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">{{ $t->destinations->isNotEmpty() ? 'Ver recebimento de processo' : 'Recebimento de processo' }}</button>
                                @endif
                                <button type="button" wire:click="edit({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">Editar</button>
                                @if (in_array($t->type, ['income', 'expense'], true) && ! $t->processReceipt)
                                    <button type="button" wire:click="openAllocation({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">{{ $t->allocations->isNotEmpty() ? 'Editar rateio' : 'Ratear' }}</button>
                                @endif
                                <button type="button" class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $t->id }})">Excluir</button>
                            @endunless
                            @if ($t->isReadOnly() && $t->source_type === \App\Domains\Banking\Models\ReceiptDestinationPayment::class && $t->destinationPayment)
                                <button type="button" class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $t->id }})">Excluir</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-fx.table>
            <div class="mt-space-4">{{ $transactions->links() }}</div>
        @endif
    </x-fx.card>

    @if ($deletingTransaction)
        <div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" x-data @keydown.escape.window="$wire.cancelDeletion()">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelDeletion" aria-label="Cancelar exclusão"></button>
            <div role="alertdialog" aria-modal="true" aria-labelledby="transaction-delete-title" aria-describedby="transaction-delete-description" x-trap.inert.noscroll="true" class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-white shadow-elevated">
                <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
                    <h3 id="transaction-delete-title" class="text-lg font-bold text-mono-900">Excluir lançamento?</h3>
                    <button type="button" wire:click="cancelDeletion" aria-label="Cancelar exclusão" class="text-mono-400"><span class="material-icons-outlined">close</span></button>
                </div>
                <div class="space-y-4 px-6 py-5 text-sm text-mono-600">
                    <p class="rounded-xl bg-mono-50 p-3 text-mono-900"><strong>{{ $deletingTransaction->description }}</strong><br>R$ {{ number_format(abs((float) $deletingTransaction->amount), 2, ',', '.') }}</p>
                    <div id="transaction-delete-description">
                        @if ($deletingTransaction->destinations_count > 0)
                            <p>Ao excluir este lançamento, todas as divisões vinculadas também serão excluídas, junto dos repasses, dos saldos devidos nas contas de repasse e das comissões gerados exclusivamente por este recebimento.</p>
                            <p class="mt-3">Comissões preexistentes e seus pagamentos, assim como saídas apenas vinculadas, serão preservados. As compensações das comissões excluídas serão desfeitas, preservando os adiantamentos originais. Os descontos de despesas serão liberados, mantendo as despesas originais disponíveis para recuperação.</p>
                        @elseif ($deletingTransaction->destinationPayment)
                            <p>O lançamento será excluído e seu pagamento será desfeito na divisão vinculada. O valor voltará a ficar pendente de repasse.</p>
                        @else
                            <p>Confirma a exclusão deste lançamento?</p>
                        @endif
                    </div>
                    @error('deletion') <p class="font-medium text-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col-reverse justify-end gap-3 border-t border-mono-100 px-6 py-4 sm:flex-row">
                    <button type="button" wire:click="cancelDeletion" class="h-11 rounded-pill bg-mono-100 px-5 text-sm font-semibold text-mono-900">Cancelar</button>
                    <button type="button" wire:click="confirmDeletion" wire:loading.attr="disabled" wire:target="confirmDeletion" class="h-11 rounded-pill bg-error px-5 text-sm font-semibold text-white disabled:opacity-50">{{ $deletingTransaction->destinations_count > 0 ? 'Excluir lançamento e divisões' : 'Excluir lançamento' }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($distributingId)
        <livewire:banking.transactions.distribution :receipt-id="$distributingId" :key="'receipt-destination-'.$distributingId" />
    @endif

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
                        <x-banking.account-picker :accounts="$activeAccounts" :selected-id="$ofxAccountId" model="ofxAccountId" id="ofx-account" label="Conta bancária *" placeholder="Selecione a conta" />
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
                                            <input id="allocation-amount-{{ $row['key'] }}" type="text" x-decimal="2" wire:model.live.debounce.300ms="allocationRows.{{ $index }}.percentage" class="h-10 w-full rounded-xl border border-mono-200 bg-white px-3 text-sm text-mono-900" placeholder="0,00" required>
                                            <p class="mt-1 text-xs text-mono-600">R$ {{ number_format((float) ($allocationPreviewRows[$index]['amount'] ?? 0), 2, ',', '.') }}</p>
                                        @else
                                            <input id="allocation-amount-{{ $row['key'] }}" type="text" x-money wire:model.live.debounce.300ms="allocationRows.{{ $index }}.amount" class="h-10 w-full rounded-xl border border-mono-200 bg-white px-3 text-sm text-mono-900" placeholder="0,00" required>
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
@endif

    @if ($showFormModal && $preview !== 'ofx')
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
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" x-data @keydown.escape.window=" $wire.cancel()" @focus-transaction-amount="$nextTick(() => { const el = document.getElementById('formAmount'); if (el) { el.focus(); if (typeof el.select === 'function') el.select(); } })">
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

                @if (! $editingId)
                    {{-- Abas do lançamento rápido / novo lançamento: trocam o tipo sem perder o que já foi digitado. --}}
                    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-mono-100 px-6 py-3" role="tablist" aria-label="Tipo do lançamento" data-quick-tabs>
                        @foreach (['expense' => ['Despesa', 'trending_down'], 'income' => ['Receita', 'trending_up'], 'transfer' => ['Transferência', 'sync_alt']] as $tabKey => [$tabLabel, $tabIcon])
                            <button type="button" role="tab" aria-selected="{{ $formType === $tabKey ? 'true' : 'false' }}" wire:click="switchType('{{ $tabKey }}')" data-quick-tab="{{ $tabKey }}" class="qe-tab">
                                <span class="material-icons-outlined text-[18px]" aria-hidden="true">{{ $tabIcon }}</span>{{ $tabLabel }}
                            </button>
                        @endforeach
                        @if ($formType === 'expense' && count($cards) > 0)
                            <button type="button" wire:click="switchType('{{ $formIsCard ? 'expense' : 'card_expense' }}')" aria-pressed="{{ $formIsCard ? 'true' : 'false' }}" data-quick-card-toggle class="qe-card-toggle">
                                <span class="material-icons-outlined text-[16px]" aria-hidden="true">credit_card</span>No cartão de crédito
                            </button>
                        @endif
                    </div>
                @endif

                <form wire:submit="saveTransaction" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        @if ($formSuccessMessage)
                            <p class="mb-4 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success" role="status" data-test="form-success-message">{{ $formSuccessMessage }}</p>
                        @endif
                        @error('transaction') <p class="mb-4 text-sm font-medium text-error" role="alert">{{ $message }}</p> @enderror
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">receipt_long</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados do lançamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    @if ($editingId && ! $formIsCard && ! $editingHasAllocations && ! $editingProcessReceipt)
                                        {{-- Corrigir o tipo: despesa/receita lançada por engano vira transferência (e vice-versa) sem deixar lado órfão. --}}
                                        <div class="md:col-span-2" role="group" aria-label="Tipo do lançamento" data-test="edit-type-switch">
                                            <span class="mb-2 block text-sm font-medium text-mono-600">Tipo</span>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach (['expense' => 'Despesa', 'income' => 'Receita', 'transfer' => 'Transferência'] as $typeKey => $typeLabel)
                                                    <button type="button" wire:click="$set('formType', '{{ $typeKey }}')" aria-pressed="{{ $formType === $typeKey ? 'true' : 'false' }}" class="min-h-[40px] rounded-pill border px-4 text-sm font-semibold {{ $formType === $typeKey ? 'border-primary-500 bg-primary-500 text-white' : 'border-mono-200 text-mono-700' }}">{{ $typeLabel }}</button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    <x-jr.input label="Valor *" icon="payments" name="formAmount" wire:model.live.debounce.300ms="formAmount" x-money required />
                                    <x-jr.input label="Data *" icon="calendar_month" name="formDate" type="date" wire:model.live="formDate" required />
                                    @if ($formIsHistorical)
                                        <p class="mt-1 text-xs font-medium text-warning" data-test="historical-warning">Lançamento histórico: registra no módulo, mas não altera o saldo da conta.</p>
                                    @endif

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

                            @if ($formType === 'income')
                                <div class="rounded-xl bg-blue-50 p-4">
                                    <label class="flex cursor-pointer items-center gap-3 text-sm font-semibold text-mono-900"><input type="checkbox" wire:model.live="formDestinateAfterSave" @disabled($editingProcessReceipt) class="rounded border-mono-200 text-primary-500 focus:ring-primary-500">Recebimento de processo</label>
                                    <p class="mt-2 text-xs text-mono-600">Separe cliente e honorários, distribua as comissões e escolha os descontos do corretor após salvar. Disponível para valores já recebidos em conta.</p>
                                    @if ($editingProcessReceipt)<p class="mt-2 text-xs text-mono-600">Para desmarcar, remova a divisão em Ver recebimento de processo.</p>@endif
                                </div>
                            @endif

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance_wallet</span>
                                    <h4 class="text-base font-bold text-mono-900">Classificação e pagamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    @if (in_array($formType, ['income', 'expense'], true) && ! ($formType === 'income' && $formDestinateAfterSave))
                                        @if (! $formIsCardRefund)
                                        <div class="md:col-span-2">
                                            <label class="inline-flex min-h-10 cursor-pointer items-center gap-3 text-sm font-semibold text-mono-900">
                                                <input type="checkbox" wire:model.live="formAllocationEnabled" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500">
                                                Dividir este lançamento em categorias
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
                                                            :selected-id="$row['category_id'] ?? null" :model="'formAllocationRows.'.$index.'.category_id'" :id="'form-allocation-'.$row['key']" :label="'Categoria '.($index + 1)" />
                                                        @if ($formAllocationMode === 'percentage')
                                                            <x-jr.input label="Percentual (%)" :name="'formAllocationRows.'.$index.'.percentage'" type="text" x-decimal="2" wire:model.live.debounce.300ms="formAllocationRows.{{ $index }}.percentage" :helper="'R$ '.number_format((float) ($formAllocationPreviewRows[$index]['amount'] ?? 0), 2, ',', '.')" required />
                                                        @else
                                                            <x-jr.input label="Valor (R$)" :name="'formAllocationRows.'.$index.'.amount'" type="text" x-money wire:model.live.debounce.300ms="formAllocationRows.{{ $index }}.amount" required />
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
                                                    <span>Total: <strong>R$ {{ number_format(is_numeric($formAmount) ? (float) $formAmount : 0, 2, ',', '.') }}</strong></span>
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
                                                <x-banking.category-picker :categories="$activeCategories->where('type', $formType)" :selected-id="$formCategoryId" />
                                                @error('formCategoryId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            </div>
                                        @endif
                                    @endif

                                    @if ($formType === 'transfer')
                                        <div>
                                            <x-banking.account-picker :accounts="$activeAccounts" :selected-id="$formBankAccountId" model="formBankAccountId" id="transaction-bank-account" label="Conta de origem *" placeholder="Selecionar" />
                                            @error('formBankAccountId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                        <div>
                                            <x-banking.account-picker :accounts="$activeAccounts" :selected-id="$formTransferToId" model="formTransferToId" id="transaction-transfer-account" label="Conta de destino *" placeholder="Selecionar" />
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
                                            <x-banking.account-picker :accounts="$activeAccounts" :selected-id="$formBankAccountId" model="formBankAccountId" id="transaction-bank-account" label="Conta bancária" placeholder="Nenhuma" />
                                            @error('formBankAccountId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                    @endif
                                </div>
                            </section>

                            @if ($formType !== 'transfer')
                            <section>
                                @php
                                    $optionalOpen = $showOptionalLinks || $errors->hasAny(['formLinkProcess', 'formLinkWritId', 'formLinkBrokerId', 'formLinkContactId', 'formAttachment']);
                                    $optionalCount = (trim($formLinkProcess) !== '' ? 1 : 0) + ($formLinkWritId ? 1 : 0) + ($formLinkBrokerId ? 1 : 0) + ($formLinkContactId ? 1 : 0) + ($formAttachment ? 1 : 0);
                                @endphp
                                <button type="button" wire:click="toggleOptionalLinks" aria-expanded="{{ $optionalOpen ? 'true' : 'false' }}" aria-controls="optional-links-panel" data-optional-links-toggle class="inline-flex items-center gap-1 rounded text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                                    <span class="material-icons-outlined text-[16px]">{{ $optionalOpen ? 'expand_less' : 'add_link' }}</span>
                                    {{ $optionalOpen ? 'Ocultar vínculos e comprovante' : 'Adicionar vínculos ou comprovante (opcional)' }}
                                    @if (! $optionalOpen && $optionalCount > 0)<span class="text-mono-500">· {{ $optionalCount }} preenchido(s)</span>@endif
                                </button>
                                @if ($optionalOpen)
                                <div id="optional-links-panel" class="mt-4">
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">link</span>
                                    <h4 class="text-base font-bold text-mono-900">Vínculos e comprovante <span class="text-sm font-normal text-mono-500">(opcionais)</span></h4>
                                </div>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2"><x-jr.input label="Buscar requisitório, corretor ou pessoa" icon="search" name="linkSearch" wire:model.live.debounce.400ms="linkSearch" placeholder="Nome ou número do processo" /></div>
                                    <x-jr.input label="Processo (número)" name="formLinkProcess" wire:model="formLinkProcess" x-process-number maxlength="120" />
                                    <div><label for="form-link-writ" class="mb-2 block text-sm font-medium text-mono-600">Requisitório</label><select id="form-link-writ" wire:model="formLinkWritId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900"><option value="">Nenhum</option>@foreach ($linkOptions['writs'] as $o)<option value="{{ $o->id }}">{{ $o->beneficiaryName() }} · {{ $o->process_number ?: '#'.$o->id }}</option>@endforeach</select>@error('formLinkWritId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror</div>
                                    <div><label for="form-link-broker" class="mb-2 block text-sm font-medium text-mono-600">Corretor</label><select id="form-link-broker" wire:model="formLinkBrokerId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900"><option value="">Nenhum</option>@foreach ($linkOptions['brokers'] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>@error('formLinkBrokerId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror</div>
                                    <div><label for="form-link-contact" class="mb-2 block text-sm font-medium text-mono-600">Pessoa (contato)</label><select id="form-link-contact" wire:model="formLinkContactId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900"><option value="">Nenhuma</option>@foreach ($linkOptions['contacts'] as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>@error('formLinkContactId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror</div>
                                    <div class="md:col-span-2">
                                        <label for="form-attachment" class="mb-2 block text-sm font-medium text-mono-600">Anexar comprovante (PDF, JPG ou PNG, até 10 MB)</label>
                                        <input id="form-attachment" type="file" wire:model="formAttachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="block w-full text-sm text-mono-900">
                                        <div wire:loading wire:target="formAttachment" class="mt-1 text-xs text-mono-600">Enviando…</div>
                                        @error('formAttachment') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        @if ($editingId)
                                            @foreach (\App\Domains\Banking\Models\TransactionAttachment::where('transaction_id', $editingId)->orderBy('id')->get() as $file)
                                                <p class="mt-2 text-sm" wire:key="form-attachment-{{ $file->id }}"><a href="{{ route('banking.transactions.attachments.download', $file) }}" class="font-semibold text-primary-600">{{ $file->original_name }}</a> <button type="button" wire:click="deleteAttachment({{ $file->id }})" wire:confirm="Remover este comprovante?" class="ml-2 text-xs text-error">remover</button></p>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                                </div>
                                @endif
                            </section>
                            @endif

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

                    <div class="flex shrink-0 flex-col gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            @if (($formIsCard || $formType === 'expense') && ! $formIsCardRefund)
                                <button type="button" wire:click="payForSomeone" wire:loading.attr="disabled" wire:target="payForSomeone" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-pill border border-primary-200 bg-primary-50 px-5 text-sm font-semibold text-primary-600 transition-colors hover:bg-primary-100 sm:w-auto">
                                    <span class="material-icons-outlined text-[18px]" aria-hidden="true">request_quote</span>
                                    Paguei por alguém
                                </button>
                                <p class="mt-1 text-xs text-mono-600">Abre Valores a receber com estes dados. Não cria outra saída — a parte de terceiros é separada da despesa própria.</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center justify-end gap-3">
                            <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200 disabled:opacity-50" wire:click="cancel" wire:loading.attr="disabled" wire:target="saveTransaction,saveAndCreateAnother">Cancelar</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveTransaction,saveAndCreateAnother" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600 disabled:opacity-50">
                                <span class="material-icons-outlined text-[18px]">check</span>
                                {{ $editingId ? 'Salvar Lançamento' : 'Salvar' }}
                            </button>
                            @if (! $editingId)
                                <button type="button" wire:click="saveAndCreateAnother" wire:loading.attr="disabled" wire:target="saveTransaction,saveAndCreateAnother" class="inline-flex h-11 items-center gap-2 rounded-pill border border-primary-200 bg-primary-50 px-5 text-sm font-semibold text-primary-700 transition-colors hover:bg-primary-100 disabled:opacity-50" data-test="save-and-create-another">
                                    <span class="material-icons-outlined text-[18px]" aria-hidden="true">add_task</span>
                                    Salvar e lançar outro
                                </button>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

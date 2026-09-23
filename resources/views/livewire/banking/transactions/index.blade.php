<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\OfxImportService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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

    public array $ofxPreviewRows = [];

    public int $ofxTotal = 0;

    public int $ofxDuplicates = 0;

    public ?string $ofxStatementAccount = null;

    public ?string $ofxPreviewHash = null;

    public ?int $ofxPreviewAccountId = null;

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

    public ?int $formTransferToId = null;

    public int $formInstallments = 1;

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
        $this->resetImport();
        $this->showImportModal = true;
    }

    public function closeImport(): void
    {
        $this->resetImport();
        $this->showImportModal = false;
    }

    public function updatedOfxFile(): void
    {
        $this->clearImportPreview();
    }

    public function updatedOfxAccountId(): void
    {
        $this->clearImportPreview();
    }

    public function previewImport(OfxImportService $service): void
    {
        $this->validateImport();

        try {
            $parsed = $service->parse(file_get_contents($this->ofxFile->getRealPath()));
            $account = BankAccount::active()->findOrFail($this->ofxAccountId);
            $existing = $service->existingFitids($account, $parsed['transactions']);
            $this->ofxPreviewRows = array_slice(array_map(
                fn (array $row) => $row + ['duplicate' => isset($existing[$row['fitid']])],
                $parsed['transactions'],
            ), 0, 20);
            $this->ofxTotal = count($parsed['transactions']);
            $this->ofxDuplicates = count($existing);
            $this->ofxStatementAccount = $parsed['account'];
            $this->ofxPreviewHash = hash_file('sha256', $this->ofxFile->getRealPath());
            $this->ofxPreviewAccountId = $account->id;
        } catch (\InvalidArgumentException $e) {
            $this->clearImportPreview();
            $this->addError('ofxFile', $e->getMessage());
        }
    }

    public function confirmImport(OfxImportService $service): void
    {
        $this->validateImport();

        if (! $this->ofxPreviewHash || $this->ofxPreviewAccountId !== $this->ofxAccountId
            || ! hash_equals($this->ofxPreviewHash, hash_file('sha256', $this->ofxFile->getRealPath()))) {
            $this->addError('ofxFile', 'Confira a prévia novamente antes de importar.');

            return;
        }

        try {
            $parsed = $service->parse(file_get_contents($this->ofxFile->getRealPath()));
            $account = BankAccount::active()->findOrFail($this->ofxAccountId);
            $result = $service->import($account, $parsed['transactions']);
        } catch (\InvalidArgumentException $e) {
            $this->addError('ofxFile', $e->getMessage());

            return;
        }

        $dates = array_column($parsed['transactions'], 'date');
        $this->from = min($dates);
        $this->to = max($dates);
        $this->account = (string) $account->id;
        $this->category = $this->status = $this->type = '';
        $this->resetPage();
        $this->closeImport();
        session()->flash('status', "OFX importado: {$result['imported']} lançamento(s) novo(s) e {$result['skipped']} já importado(s) nesta conta.");
    }

    private function validateImport(): void
    {
        $this->validate([
            'ofxAccountId' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('status', true)->whereNull('deleted_at')],
            'ofxFile' => 'required|file|max:2048',
        ]);

        if (strtolower(pathinfo($this->ofxFile->getClientOriginalName(), PATHINFO_EXTENSION)) !== 'ofx') {
            throw \Illuminate\Validation\ValidationException::withMessages(['ofxFile' => 'Selecione um arquivo com extensão .ofx.']);
        }
    }

    private function clearImportPreview(): void
    {
        $this->reset(['ofxPreviewRows', 'ofxTotal', 'ofxDuplicates', 'ofxStatementAccount', 'ofxPreviewHash', 'ofxPreviewAccountId']);
        $this->resetValidation();
    }

    private function resetImport(): void
    {
        $this->reset(['ofxFile', 'ofxAccountId']);
        $this->clearImportPreview();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $transaction = Transaction::findOrFail($id);

        if ($transaction->isReadOnly()) {
            session()->flash('error', 'Lançamento gerado por outro módulo é somente leitura.');

            return;
        }

        $this->editingId = $transaction->id;
        $this->formType = $transaction->type;
        $this->formDate = $transaction->date->format('Y-m-d');
        $this->formAmount = (string) abs((float) $transaction->amount);
        $this->formDescription = $transaction->description;
        $this->formNotes = (string) $transaction->notes;
        $this->formStatus = $transaction->status;
        $this->formCategoryId = $transaction->category_id;
        $this->formBankAccountId = $transaction->bank_account_id;
        $this->formCreditCardId = $transaction->credit_card_id;
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function saveTransaction(TransactionService $service, TransferService $transfer, InstallmentService $installment): void
    {
        $data = $this->validate([
            'formType' => 'required|in:income,expense,transfer',
            'formDate' => 'required|date',
            'formAmount' => 'required|numeric|min:0.01',
            'formDescription' => 'required|string|max:200',
            'formNotes' => 'nullable|string',
            'formStatus' => 'required|in:pending,settled',
            'formCategoryId' => 'nullable|exists:categories,id',
            'formBankAccountId' => 'nullable|exists:bank_accounts,id',
            'formCreditCardId' => 'nullable|exists:credit_cards,id',
            'formTransferToId' => 'nullable|exists:bank_accounts,id|different:formBankAccountId',
            'formInstallments' => 'required|integer|min:1|max:36',
        ]);

        if ($this->editingId) {
            $transaction = Transaction::findOrFail($this->editingId);
            $service->update($transaction, [
                'type' => $data['formType'],
                'date' => $data['formDate'],
                'amount' => $data['formAmount'],
                'description' => $data['formDescription'],
                'notes' => $data['formNotes'] ?: null,
                'status' => $data['formStatus'],
                'category_id' => $data['formCategoryId'],
                'bank_account_id' => $data['formBankAccountId'],
                'credit_card_id' => $data['formCreditCardId'],
            ]);
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
                $data['formDescription'],
                $data['formNotes'] ?: null,
            );
            $message = 'Transferência criada.';
        } elseif ($data['formType'] === 'expense' && $data['formCreditCardId']) {
            $installment->split(
                CreditCard::findOrFail($data['formCreditCardId']),
                Carbon::parse($data['formDate']),
                (float) $data['formAmount'],
                $data['formInstallments'],
                $data['formDescription'],
                $data['formCategoryId'],
                $data['formNotes'] ?: null,
            );
            $message = $data['formInstallments'] > 1 ? 'Compra parcelada criada.' : 'Lançamento criado.';
        } else {
            $service->create([
                'type' => $data['formType'],
                'date' => $data['formDate'],
                'amount' => $data['formAmount'],
                'description' => $data['formDescription'],
                'notes' => $data['formNotes'] ?: null,
                'status' => $data['formStatus'],
                'category_id' => $data['formCategoryId'],
                'bank_account_id' => $data['formBankAccountId'],
            ]);
            $message = 'Lançamento criado.';
        }

        $this->resetForm();
        $this->resetPage();
        session()->flash('status', $message);
    }

    private function resetForm(): void
    {
        $this->reset([
            'showFormModal', 'editingId', 'formAmount', 'formDescription', 'formNotes',
            'formCategoryId', 'formBankAccountId', 'formCreditCardId', 'formTransferToId',
        ]);
        $this->formType = 'expense';
        $this->formDate = now()->format('Y-m-d');
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
        $q = Transaction::with(['category', 'bankAccount', 'creditCard']);

        if ($this->from) {
            $q->where('date', '>=', $this->from);
        }
        if ($this->to) {
            $q->where('date', '<=', $this->to);
        }
        if ($this->category) {
            $q->where('category_id', $this->category);
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
            'cards' => CreditCard::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-space-5">
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
                <x-jr.button type="button" size="sm" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Novo lançamento
                </x-jr.button>
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
                <x-jr.button type="button" class="mt-space-4" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Novo lançamento
                </x-jr.button>
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
                            @if ($t->status === 'pending')
                                <x-fx.badge variant="warning" class="ml-space-2">pendente</x-fx.badge>
                            @endif
                        </td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $t->category?->name }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $t->bankAccount?->name ?? $t->creditCard?->name }}</td>
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums] {{ $t->type === 'income' ? 'text-cryptex-green-500' : ($t->type === 'transfer' ? 'text-cryptex-text-primary' : 'text-cryptex-red-500') }}">
                            R$ {{ number_format(abs((float) $t->amount), 2, ',', '.') }}
                        </td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            @unless ($t->isReadOnly())
                                <button type="button" wire:click="edit({{ $t->id }})" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3">Editar</button>
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
                    <div>
                        <label for="ofx-file" class="mb-2 block text-sm font-medium text-mono-600">Arquivo OFX (até 2 MB) *</label>
                        <input id="ofx-file" type="file" accept=".ofx" wire:model="ofxFile" class="block w-full rounded-xl border border-mono-200 p-3 text-sm text-mono-700">
                        <p wire:loading wire:target="ofxFile" class="mt-2 text-xs text-mono-500">Carregando arquivo...</p>
                        @error('ofxFile') <p class="mt-2 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($ofxPreviewHash)
                        <div class="rounded-xl bg-mono-50 p-4 text-sm text-mono-700">
                            <p><strong>{{ $ofxTotal }}</strong> lançamento(s) no arquivo; <strong>{{ $ofxDuplicates }}</strong> já importado(s) nesta conta; <strong>{{ $ofxTotal - $ofxDuplicates }}</strong> novo(s).</p>
                            @if ($ofxStatementAccount)
                                <p class="mt-1">Conta informada no OFX: {{ $ofxStatementAccount }}. Confira se corresponde à conta selecionada.</p>
                            @endif
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-mono-700">
                                <thead><tr class="border-b border-mono-200 text-left"><th class="p-2">Data</th><th class="p-2">Descrição</th><th class="p-2 text-right">Valor</th><th class="p-2">Situação</th></tr></thead>
                                <tbody>
                                    @foreach ($ofxPreviewRows as $row)
                                        <tr class="border-b border-mono-100">
                                            <td class="p-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                                            <td class="p-2">{{ $row['description'] }}</td>
                                            <td class="p-2 text-right whitespace-nowrap {{ $row['type'] === 'income' ? 'text-green-700' : 'text-red-700' }}">{{ $row['type'] === 'income' ? '+' : '-' }} R$ {{ number_format((float) $row['amount'], 2, ',', '.') }}</td>
                                            <td class="p-2">{{ $row['duplicate'] ? 'Já importado' : 'Novo' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if ($ofxTotal > 20) <p class="mt-2 text-xs text-mono-500">Exibindo os primeiros 20 lançamentos.</p> @endif
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                    <button type="button" wire:click="closeImport" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900">Cancelar</button>
                    @if ($ofxPreviewHash)
                        <button type="button" wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport,ofxFile" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Importar {{ $ofxTotal - $ofxDuplicates }} novo(s)</button>
                    @else
                        <button type="button" wire:click="previewImport" wire:loading.attr="disabled" wire:target="previewImport,ofxFile" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Conferir prévia</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Lançamento' : 'Novo Lançamento' }}</h3>
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

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                                        <select wire:model.live="formType" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" @disabled($editingId)>
                                            <option value="expense">Despesa</option>
                                            <option value="income">Receita</option>
                                            <option value="transfer">Transferência</option>
                                        </select>
                                        @error('formType') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <x-jr.input label="Data *" icon="calendar_month" name="formDate" type="date" wire:model="formDate" required />
                                    <x-jr.input label="Valor *" icon="payments" name="formAmount" wire:model="formAmount" x-money required />

                                    <div class="md:col-span-2">
                                        <x-jr.input label="Descrição *" icon="description" name="formDescription" wire:model="formDescription" maxlength="200" required />
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Status *</label>
                                        <select wire:model="formStatus" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="settled">Liquidado</option>
                                            <option value="pending">Pendente</option>
                                        </select>
                                        @error('formStatus') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance_wallet</span>
                                    <h4 class="text-base font-bold text-mono-900">Classificação e pagamento</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Categoria</label>
                                        <select wire:model="formCategoryId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="">Sem categoria</option>
                                            @foreach ($activeCategories as $categoryOption)
                                                <option value="{{ $categoryOption->id }}">{{ $categoryOption->name }} ({{ $categoryOption->type === 'income' ? 'Receita' : 'Despesa' }})</option>
                                            @endforeach
                                        </select>
                                        @error('formCategoryId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    @if ($formType === 'transfer')
                                        <div></div>
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

                                        @if ($formType === 'expense')
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-mono-600">Cartão de crédito</label>
                                                <select wire:model.live="formCreditCardId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                                    <option value="">Nenhum</option>
                                                    @foreach ($cards as $cardOption)
                                                        <option value="{{ $cardOption->id }}">{{ $cardOption->name }}</option>
                                                    @endforeach
                                                </select>
                                                @error('formCreditCardId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            </div>

                                            @if ($formCreditCardId && ! $editingId)
                                                <x-jr.input label="Parcelas" icon="view_week" name="formInstallments" type="number" min="1" max="36" wire:model="formInstallments" />
                                            @endif
                                        @endif
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
</div>

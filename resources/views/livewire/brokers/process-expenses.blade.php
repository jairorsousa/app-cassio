<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use App\Support\ColumnSorter;
use App\Support\Concerns\SortsColumns;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    use SortsColumns;

    #[Locked] public int $brokerId;
    #[Locked] public ?int $editingId = null;
    public bool $showForm = false;
    public string $date = '';
    public string $amount = '';
    public string $description = '';
    public ?int $accountId = null;
    public string $process_ref = '';
    public ?int $contactId = null;
    public bool $createAnotherAfterSave = false;
    public ?string $formSuccessMessage = null;

    public function mount(): void
    {
        $this->loadSort();
    }

    protected function sortListKey(): string
    {
        return 'broker_process_expenses';
    }

    public function create(): void
    {
        $this->reset('editingId', 'amount', 'description', 'accountId', 'process_ref', 'contactId', 'formSuccessMessage');
        $this->date = now('America/Sao_Paulo')->toDateString();
        $this->createAnotherAfterSave = false;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function saveAndCreateAnother(BrokerProcessExpenseService $service): void
    {
        if ($this->editingId) {
            $this->save($service);

            return;
        }

        $this->createAnotherAfterSave = true;
        $this->save($service);
    }

    public function edit(int $id): void
    {
        $expense = BrokerProcessExpense::where('broker_id', $this->brokerId)->findOrFail($id);
        $this->editingId = $id;
        $this->date = $expense->date->toDateString();
        $this->amount = (string) $expense->amount;
        $this->description = $expense->description;
        $this->accountId = $expense->bank_account_id;
        $this->process_ref = (string) ($expense->process_ref ?? '');
        $this->contactId = $expense->contact_id;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(BrokerProcessExpenseService $service): void
    {
        $this->amount = \App\Support\Money::normalize($this->amount);
        $createAnother = $this->createAnotherAfterSave;
        $this->createAnotherAfterSave = false;
        $this->formSuccessMessage = null;

        try {
            $service->save(Broker::findOrFail($this->brokerId), [
                'date' => $this->date, 'amount' => $this->amount, 'description' => $this->description, 'bank_account_id' => $this->accountId, 'process_ref' => $this->process_ref, 'contact_id' => $this->contactId,
            ], $this->editingId ? BrokerProcessExpense::where('broker_id', $this->brokerId)->findOrFail($this->editingId) : null);
        } catch (\DomainException $e) { $this->addError('expense', $e->getMessage()); return; }

        $this->dispatch('broker-process-expenses-updated');
        $this->resetValidation();

        if ($createAnother) {
            $this->reset('editingId', 'amount', 'description', 'accountId', 'process_ref', 'contactId');
            $this->date = now('America/Sao_Paulo')->toDateString();
            $this->showForm = true;
            $this->formSuccessMessage = 'Despesa salva.';

            return;
        }

        $this->showForm = false;
        $this->formSuccessMessage = null;
    }

    public function delete(int $id, BrokerProcessExpenseService $service): void
    {
        try { $service->delete(BrokerProcessExpense::where('broker_id', $this->brokerId)->findOrFail($id)); }
        catch (\DomainException $e) { $this->addError('expense', $e->getMessage()); return; }
        if ($this->editingId === $id) { $this->showForm = false; }
        $this->resetValidation();
        $this->dispatch('broker-process-expenses-updated');
    }

    public function with(): array
    {
        [$by, $dir] = $this->sortState();
        $expenses = BrokerProcessExpense::where('broker_id', $this->brokerId)->with('bankAccount', 'contact', 'recoveries.destination.receipt')->orderByDesc('date')->orderByDesc('id')->get();
        $expenses = ColumnSorter::sort($expenses, $by, $dir, [
            'date' => fn ($e) => $e->date,
            'description' => fn ($e) => $e->description,
            'account' => fn ($e) => $e->bankAccount?->name ?: '',
            'amount' => fn ($e) => (float) $e->amount,
            'status' => fn ($e) => $e->remainingCents() === 0 ? 'Recuperada' : ($e->recoveredCents() > 0 ? 'Parcialmente recuperada' : 'A recuperar'),
            'balance' => fn ($e) => $e->remainingCents() / 100,
        ], $by === 'date' ? [[fn ($e) => $e->id, $dir]] : [[fn ($e) => $e->date, 'desc'], [fn ($e) => $e->id, 'desc']]);

        return [
            'expenses' => $expenses,
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'contacts' => \App\Domains\Contacts\Models\Contact::active()->orderBy('name')->limit(300)->get(),
            'openTotal' => BrokerProcessExpense::where('broker_id', $this->brokerId)->with('recoveries')->get()->sum(fn ($e) => $e->remainingCents()) / 100,
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-mono-600">Dinheiro do <strong>cliente</strong> antecipado pelo Cassio (custas etc.). Não entra no saldo pessoal do corretor. Recupera-se no recebimento do processo.</p>
        <button type="button" wire:click="create" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-white">Nova despesa</button>
    </div>
    <div class="grid gap-3 text-sm sm:grid-cols-3">
        <div class="rounded-xl bg-mono-50 p-4">Total de despesas<strong class="block">R$ {{ number_format($expenses->sum('amount'), 2, ',', '.') }}</strong></div>
        <div class="rounded-xl bg-green-50 p-4">Recuperado<strong class="block">R$ {{ number_format($expenses->sum(fn ($expense) => $expense->recoveredCents()) / 100, 2, ',', '.') }}</strong></div>
        <div class="rounded-xl bg-orange-50 p-4">A recuperar<strong class="block">R$ {{ number_format($expenses->sum(fn ($expense) => $expense->remainingCents()) / 100, 2, ',', '.') }}</strong></div>
    </div>
    @foreach ($errors->all() as $message)<p class="text-sm text-error" role="alert">{{ $message }}</p>@endforeach
    @if ($showForm)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-primary-100 bg-primary-50 p-4">
            <h4 class="font-semibold text-mono-900">{{ $editingId ? 'Editar despesa de processos' : 'Nova despesa de processos' }}</h4>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-jr.input label="Valor (R$) *" name="amount" type="text" x-money wire:model="amount" required />
                <x-jr.input label="Data *" name="date" type="date" wire:model="date" required />
                <x-jr.input label="Descrição *" name="description" wire:model="description" maxlength="200" required />
                <x-jr.input label="Processo (número)" name="process_ref" wire:model="process_ref" x-process-number maxlength="120" />
                <div><label class="mb-2 block text-sm text-mono-600">Cliente (contato)</label><select wire:model="contactId" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">— opcional —</option>@foreach ($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</select></div>
                <div><label for="process-expense-account" class="mb-2 block text-sm text-mono-600">Conta do pagamento *</label><select id="process-expense-account" wire:model="accountId" class="h-12 w-full rounded-xl border-mono-200 text-sm" required><option value="">Selecionar</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
            </div>
            <p class="text-xs text-mono-600">Ao salvar, a despesa será registrada como uma saída paga na conta selecionada.</p>
            @if ($formSuccessMessage)
                <div class="rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success" data-test="form-success-message">{{ $formSuccessMessage }}</div>
            @endif
            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="$set('showForm', false)" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="h-11 rounded-pill bg-mono-100 px-5 text-sm font-semibold text-mono-900 disabled:opacity-50">Cancelar</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-white disabled:opacity-50">Salvar</button>
                @if (! $editingId)
                    <button type="button" wire:click="saveAndCreateAnother" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="inline-flex h-11 items-center gap-2 rounded-pill border border-primary-200 bg-primary-50 px-5 text-sm font-semibold text-primary-700 disabled:opacity-50" data-test="save-and-create-another">Salvar e novo</button>
                @endif
            </div>
        </form>
    @endif
    @if ($expenses->isEmpty())
        <p class="rounded-xl bg-mono-50 p-5 text-sm text-mono-600">Nenhuma despesa de processos cadastrada para este corretor.</p>
    @else
        <x-fx.sort-mobile list="broker_process_expenses" :sort-by="$sortBy" :sort-dir="$sortDir" primary />
        <div class="overflow-x-auto">
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <x-fx.sort-th column="date" label="Data" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="description" label="Descrição" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="account" label="Conta" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="amount" label="Valor" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="status" label="Situação" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <x-fx.sort-th column="balance" label="Saldo" align="right" :sort-by="$sortBy" :sort-dir="$sortDir" />
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expenses as $expense)
                        <tr wire:key="process-expense-{{ $expense->id }}">
                            <td>{{ $expense->date->format('d/m/Y') }}</td>
                            <td>
                                <strong class="text-mono-900">{{ $expense->description }}</strong>
                                @if ($expense->recoveries->isNotEmpty())
                                    <details class="mt-1"><summary class="cursor-pointer text-primary-600">Ver descontos realizados</summary>@foreach ($expense->recoveries as $recovery)<p class="mt-2 text-xs text-mono-600">Recebimento #{{ $recovery->destination->receipt_id }} · {{ $recovery->destination->receipt?->description }} · R$ {{ number_format((float) $recovery->amount, 2, ',', '.') }}</p>@endforeach</details>
                                @endif
                            </td>
                            <td>{{ $expense->bankAccount?->name }}</td>
                            <td class="text-right font-semibold">R$ {{ number_format((float) $expense->amount, 2, ',', '.') }}</td>
                            <td class="text-mono-600">{{ $expense->remainingCents() === 0 ? 'Recuperada' : ($expense->recoveredCents() > 0 ? 'Parcialmente recuperada' : 'A recuperar') }}</td>
                            <td class="text-right">Saldo disponível: <strong>R$ {{ number_format($expense->remainingCents() / 100, 2, ',', '.') }}</strong></td>
                            <td class="text-right whitespace-nowrap"><button type="button" wire:click="edit({{ $expense->id }})" class="text-primary-600">Editar</button> <button type="button" wire:click="delete({{ $expense->id }})" wire:confirm="Excluir esta despesa e a saída correspondente na conta?" class="text-error">Excluir</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

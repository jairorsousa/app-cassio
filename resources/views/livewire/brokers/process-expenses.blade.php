<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Brokers\Services\BrokerProcessExpenseService;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked] public int $brokerId;
    #[Locked] public ?int $editingId = null;
    public bool $showForm = false;
    public string $date = '';
    public string $amount = '';
    public string $description = '';
    public ?int $accountId = null;

    public function create(): void
    {
        $this->reset('editingId', 'amount', 'description', 'accountId');
        $this->date = now('America/Sao_Paulo')->toDateString();
        $this->showForm = true;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $expense = BrokerProcessExpense::where('broker_id', $this->brokerId)->findOrFail($id);
        $this->editingId = $id;
        $this->date = $expense->date->toDateString();
        $this->amount = (string) $expense->amount;
        $this->description = $expense->description;
        $this->accountId = $expense->bank_account_id;
        $this->showForm = true;
        $this->resetValidation();
    }

    public function save(BrokerProcessExpenseService $service): void
    {
        try {
            $service->save(Broker::findOrFail($this->brokerId), [
                'date' => $this->date, 'amount' => $this->amount, 'description' => $this->description, 'bank_account_id' => $this->accountId,
            ], $this->editingId ? BrokerProcessExpense::where('broker_id', $this->brokerId)->findOrFail($this->editingId) : null);
        } catch (\DomainException $e) { $this->addError('expense', $e->getMessage()); return; }
        $this->showForm = false;
        $this->resetValidation();
        $this->dispatch('broker-process-expenses-updated');
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
        return [
            'expenses' => BrokerProcessExpense::where('broker_id', $this->brokerId)->with('bankAccount', 'recoveries.destination.receipt')->orderByDesc('date')->orderByDesc('id')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-mono-600">Despesas deste corretor, disponíveis para desconto no valor do cliente ao registrar um recebimento.</p>
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
                <x-jr.input label="Valor (R$) *" name="amount" type="number" min="0.01" step="0.01" wire:model="amount" required />
                <x-jr.input label="Data *" name="date" type="date" wire:model="date" required />
                <x-jr.input label="Descrição *" name="description" wire:model="description" maxlength="200" required />
                <div><label for="process-expense-account" class="mb-2 block text-sm text-mono-600">Conta do pagamento *</label><select id="process-expense-account" wire:model="accountId" class="h-12 w-full rounded-xl border-mono-200 text-sm" required><option value="">Selecionar</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
            </div>
            <p class="text-xs text-mono-600">Ao salvar, a despesa será registrada como uma saída paga na conta selecionada.</p>
            <div class="flex gap-3"><button type="submit" wire:loading.attr="disabled" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-white disabled:opacity-50">Salvar despesa</button><button type="button" wire:click="$set('showForm', false)" class="text-sm text-mono-600">Cancelar</button></div>
        </form>
    @endif
    @forelse ($expenses as $expense)
        <div wire:key="process-expense-{{ $expense->id }}" class="space-y-2 rounded-xl border border-mono-100 p-4 text-sm">
            <div class="flex flex-wrap justify-between gap-3">
                <div><strong class="text-mono-900">{{ $expense->description }}</strong><p class="mt-1 text-mono-600">{{ $expense->date->format('d/m/Y') }} · {{ $expense->bankAccount?->name }} · R$ {{ number_format((float) $expense->amount, 2, ',', '.') }}</p></div>
                <div class="flex items-start gap-3"><button type="button" wire:click="edit({{ $expense->id }})" class="text-primary-600">Editar</button><button type="button" wire:click="delete({{ $expense->id }})" wire:confirm="Excluir esta despesa e a saída correspondente na conta?" class="text-error">Excluir</button></div>
            </div>
            <p class="text-mono-600">{{ $expense->remainingCents() === 0 ? 'Recuperada' : ($expense->recoveredCents() > 0 ? 'Parcialmente recuperada' : 'A recuperar') }} · Saldo disponível: <strong>R$ {{ number_format($expense->remainingCents() / 100, 2, ',', '.') }}</strong></p>
            @if ($expense->recoveries->isNotEmpty())<details><summary class="cursor-pointer text-primary-600">Ver descontos realizados</summary>@foreach ($expense->recoveries as $recovery)<p class="mt-2 text-xs text-mono-600">Recebimento #{{ $recovery->destination->receipt_id }} · {{ $recovery->destination->receipt?->description }} · R$ {{ number_format((float) $recovery->amount, 2, ',', '.') }}</p>@endforeach</details>@endif
        </div>
    @empty<p class="rounded-xl bg-mono-50 p-5 text-sm text-mono-600">Nenhuma despesa de processos cadastrada para este corretor.</p>@endforelse
</div>

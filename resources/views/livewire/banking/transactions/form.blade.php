<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Transaction $transaction = null;

    public string $type = 'expense';
    public string $date = '';
    public string $amount = '';
    public string $description = '';
    public string $notes = '';
    public string $status = 'settled';
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public ?int $credit_card_id = null;
    public ?int $transfer_to_id = null;
    public int $installments = 1;

    public function mount(?Transaction $transaction = null): void
    {
        $this->date = now()->format('Y-m-d');

        $queryType = request()->query('type');
        if (in_array($queryType, ['income', 'expense', 'transfer'], true)) {
            $this->type = $queryType;
        }

        if ($transaction && $transaction->exists) {
            if ($transaction->isReadOnly()) {
                abort(403, 'Lançamento gerado por outro módulo é somente leitura.');
            }
            $this->transaction = $transaction;
            $this->type = $transaction->type;
            $this->date = $transaction->date->format('Y-m-d');
            $this->amount = (string) abs((float) $transaction->amount);
            $this->description = $transaction->description;
            $this->notes = (string) $transaction->notes;
            $this->status = $transaction->status;
            $this->category_id = $transaction->category_id;
            $this->bank_account_id = $transaction->bank_account_id;
            $this->credit_card_id = $transaction->credit_card_id;
        }
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:income,expense,transfer',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:200',
            'notes' => 'nullable|string',
            'status' => 'required|in:pending,settled',
            'category_id' => 'nullable|exists:categories,id',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'credit_card_id' => 'nullable|exists:credit_cards,id',
            'transfer_to_id' => 'nullable|exists:bank_accounts,id|different:bank_account_id',
            'installments' => 'required|integer|min:1|max:36',
        ];
    }

    public function save(TransactionService $service, TransferService $transfer, InstallmentService $installment)
    {
        $data = $this->validate();

        if ($this->transaction) {
            $service->update($this->transaction, [
                'type' => $data['type'],
                'date' => $data['date'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'],
                'category_id' => $data['category_id'],
                'bank_account_id' => $data['bank_account_id'],
                'credit_card_id' => $data['credit_card_id'],
            ]);
            session()->flash('status', 'Lançamento atualizado.');
            return $this->redirectRoute('banking.transactions.index', navigate: true);
        }

        if ($data['type'] === 'transfer') {
            if (! $data['bank_account_id'] || ! $data['transfer_to_id']) {
                $this->addError('transfer_to_id', 'Selecione conta origem e destino.');
                return null;
            }
            $transfer->execute(
                BankAccount::findOrFail($data['bank_account_id']),
                BankAccount::findOrFail($data['transfer_to_id']),
                (float) $data['amount'],
                $data['date'],
                $data['description'],
                $data['notes'] ?? null,
            );
        } elseif ($data['type'] === 'expense' && $data['credit_card_id'] && $data['installments'] > 1) {
            $installment->split(
                CreditCard::findOrFail($data['credit_card_id']),
                Carbon::parse($data['date']),
                (float) $data['amount'],
                $data['installments'],
                $data['description'],
                $data['category_id'],
                $data['notes'] ?? null,
            );
        } elseif ($data['type'] === 'expense' && $data['credit_card_id']) {
            $installment->split(
                CreditCard::findOrFail($data['credit_card_id']),
                Carbon::parse($data['date']),
                (float) $data['amount'],
                1,
                $data['description'],
                $data['category_id'],
                $data['notes'] ?? null,
            );
        } else {
            $service->create([
                'type' => $data['type'],
                'date' => $data['date'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'],
                'category_id' => $data['category_id'],
                'bank_account_id' => $data['bank_account_id'],
            ]);
        }

        session()->flash('status', 'Lançamento criado.');
        return $this->redirectRoute('banking.transactions.index', navigate: true);
    }

    public function with(): array
    {
        return [
            'categories' => Category::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'cards' => CreditCard::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $transaction ? 'Editar' : 'Novo' }} lançamento</x-slot>

<x-fx.card class="max-w-2xl">
    <form wire:submit="save" class="flex flex-col gap-sm">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model.live="type" class="fx-form-field" {{ $transaction ? 'disabled' : '' }}>
                    <option value="expense">Despesa</option>
                    <option value="income">Receita</option>
                    <option value="transfer">Transferência</option>
                </select>
            </div>
            <x-fx.input label="Data" type="date" wire:model="date" />
            <x-fx.input label="Valor" type="text" x-money wire:model="amount" />
        </div>

        <x-fx.input label="Descrição" wire:model="description" required />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Categoria</label>
                <select wire:model="category_id" class="fx-form-field">
                    <option value="">— sem categoria —</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->type === 'income' ? 'R' : 'D' }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model="status" class="fx-form-field">
                    <option value="settled">Liquidado</option>
                    <option value="pending">Pendente</option>
                </select>
            </div>
        </div>

        @if ($type === 'transfer')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Origem</label>
                    <select wire:model="bank_account_id" class="fx-form-field">
                        <option value="">—</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Destino</label>
                    <select wire:model="transfer_to_id" class="fx-form-field">
                        <option value="">—</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Conta bancária</label>
                    <select wire:model.live="bank_account_id" class="fx-form-field">
                        <option value="">— nenhuma —</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($type === 'expense')
                    <div>
                        <label class="block text-xxs text-mono-600 mb-xxxs">Cartão de crédito</label>
                        <select wire:model.live="credit_card_id" class="fx-form-field">
                            <option value="">— nenhum —</option>
                            @foreach ($cards as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            @if ($type === 'expense' && $credit_card_id && ! $transaction)
                <x-fx.input label="Parcelas" type="number" min="1" max="36" wire:model="installments" />
            @endif
        @endif

        <div>
            <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
            <textarea wire:model="notes" class="fx-form-field" rows="2"></textarea>
        </div>

        <div class="flex gap-xs">
            <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
            <a href="{{ route('banking.transactions.index') }}" class="fx-btn fx-btn--text">Cancelar</a>
        </div>
    </form>
</x-fx.card>

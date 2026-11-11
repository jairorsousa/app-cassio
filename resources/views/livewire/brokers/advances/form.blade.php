<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Broker $broker;

    public string $date = '';
    public string $amount = '';
    public string $payment_method = '';
    public ?int $bank_account_id = null;
    public string $notes = '';
    public bool $createAnotherAfterSave = false;
    public ?string $formSuccessMessage = null;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->date = \App\Support\BusinessDate::today();
    }

    public function saveAndCreateAnother(BrokerAdvanceService $advances)
    {
        $this->createAnotherAfterSave = true;

        return $this->save($advances);
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:50',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function save(BrokerAdvanceService $advances)
    {
        $createAnother = $this->createAnotherAfterSave;
        $this->createAnotherAfterSave = false;
        $this->formSuccessMessage = null;

        $data = $this->validate();

        $result = $advances->register([
            'broker_id' => $this->broker->id,
            'date' => $data['date'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'] ?: null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'notes' => $data['notes'] ?: null,
        ]);

        $message = BrokerAdvanceService::statusMessage($result);

        if ($createAnother) {
            $this->reset(['amount', 'payment_method', 'bank_account_id', 'notes']);
            $this->date = \App\Support\BusinessDate::today();
            $this->resetValidation();
            $this->formSuccessMessage = $message;

            return null;
        }

        session()->flash('status', $message);

        return $this->redirectRoute('brokers.advances.index', $this->broker, navigate: true);
    }

    public function with(): array
    {
        return [
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Novo adiantamento · {{ $broker->name }}</x-slot>

<x-fx.card class="max-w-2xl">
    <form wire:submit="save" class="flex flex-col gap-md">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
            <x-fx.input label="Data *" type="date" wire:model="date" />
            <x-fx.input label="Valor *" type="text" x-money wire:model="amount" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Forma de pagamento</label>
                <select wire:model="payment_method" class="fx-form-field">
                    <option value="">—</option>
                    <option value="PIX">PIX</option>
                    <option value="TED">TED</option>
                    <option value="Dinheiro">Dinheiro</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta de origem</label>
                <select wire:model="bank_account_id" class="fx-form-field">
                    <option value="">— selecionar —</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
            <textarea wire:model="notes" class="fx-form-field" rows="2"></textarea>
        </div>

        @if ($formSuccessMessage)
            <div class="rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success" data-test="form-success-message">{{ $formSuccessMessage }}</div>
        @endif
        <div class="flex flex-wrap gap-xs">
            <a href="{{ route('brokers.advances.index', $broker) }}" class="fx-btn fx-btn--text">Cancelar</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="fx-btn fx-btn--primary">Salvar</button>
            <button type="button" wire:click="saveAndCreateAnother" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="fx-btn fx-btn--standard" data-test="save-and-create-another">Salvar e novo</button>
        </div>
    </form>
</x-fx.card>

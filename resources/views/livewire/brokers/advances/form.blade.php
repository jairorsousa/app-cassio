<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Broker $broker;

    public string $date = '';
    public string $amount = '';
    public string $payment_method = '';
    public ?int $bank_account_id = null;
    public string $notes = '';

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->date = now()->format('Y-m-d');
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

    public function save()
    {
        $data = $this->validate();
        $data['broker_id'] = $this->broker->id;

        $advance = BrokerAdvance::create($data);

        BrokerAdvancePaid::dispatch($advance->load('broker'));

        session()->flash('status', 'Adiantamento registrado.');
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

        <div class="flex gap-xs">
            <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
            <a href="{{ route('brokers.advances.index', $broker) }}" class="fx-btn fx-btn--text">Cancelar</a>
        </div>
    </form>
</x-fx.card>

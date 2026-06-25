<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerCommissionService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Broker $broker;
    public ?int $case_type_id = null;
    public string $base_amount = '';
    public string $reference_date = '';
    public ?int $bank_account_id = null;
    public string $notes = '';

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->reference_date = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'case_type_id' => 'required|exists:case_types,id',
            'base_amount' => 'required|numeric|min:0.01',
            'reference_date' => 'required|date',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function save(BrokerCommissionService $service)
    {
        $data = $this->validate();
        $data['broker_id'] = $this->broker->id;
        try {
            $service->register($data);
            session()->flash('status', 'Comissão registrada.');
            return $this->redirectRoute('brokers.commissions.index', $this->broker, navigate: true);
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(): array
    {
        return [
            'caseTypes' => CaseType::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Registrar comissão · {{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

    <x-fx.card class="max-w-2xl">
        <form wire:submit="save" class="flex flex-col gap-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Tipo de caso *</label>
                    <select wire:model="case_type_id" class="fx-form-field">
                        <option value="">— selecionar —</option>
                        @foreach ($caseTypes as $ct)
                            <option value="{{ $ct->id }}">{{ $ct->name }}</option>
                        @endforeach
                    </select>
                    @if ($caseTypes->isEmpty())
                        <p class="mt-2 text-xs text-mono-600">
                            Nenhum tipo de caso cadastrado.
                            <a href="{{ route('brokers.case-types.index') }}" class="font-semibold text-primary-500 hover:text-primary-600">Cadastrar tipos de caso</a>
                        </p>
                    @endif
                </div>
                <x-fx.input label="Valor base *" type="text" x-money wire:model="base_amount" />
                <x-fx.input label="Data de referência *" type="date" wire:model="reference_date" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Conta para pagamento</label>
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
                <button type="submit" class="fx-btn fx-btn--primary">Registrar</button>
                <a href="{{ route('brokers.commissions.index', $broker) }}" class="fx-btn fx-btn--text">Cancelar</a>
            </div>
        </form>
    </x-fx.card>
</div>

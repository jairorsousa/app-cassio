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
    public string $name = '';
    public string $base_amount = '';
    public string $mode = 'percent';
    public string $rate_or_amount = '';
    public string $process_ref = '';
    public ?int $writ_id = null;
    public string $reference_date = '';
    public ?int $bank_account_id = null;
    public string $notes = '';
    public bool $createAnotherAfterSave = false;
    public ?string $formSuccessMessage = null;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker;
        $this->reference_date = \App\Support\BusinessDate::today();
    }

    public function saveAndCreateAnother(BrokerCommissionService $service)
    {
        $this->createAnotherAfterSave = true;

        return $this->save($service);
    }

    public function rules(): array
    {
        return [
            'case_type_id' => 'required|exists:case_types,id',
            'name' => 'required|string|max:160',
            'mode' => 'required|in:percent,fixed',
            'rate_or_amount' => 'required|numeric|min:0.01',
            'base_amount' => 'nullable|numeric|min:0.01',
            'process_ref' => 'nullable|string|max:120',
            'writ_id' => 'nullable|exists:writs,id',
            'reference_date' => 'required|date',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function save(BrokerCommissionService $service)
    {
        $this->rate_or_amount = \App\Support\Money::normalize($this->rate_or_amount);
        $this->base_amount = \App\Support\Money::normalize($this->base_amount);
        $createAnother = $this->createAnotherAfterSave;
        $this->createAnotherAfterSave = false;
        $this->formSuccessMessage = null;

        $data = $this->validate();
        try {
            $service->registerFlexible([
                'broker_id' => $this->broker->id,
                'case_type_id' => $data['case_type_id'],
                'name' => $data['name'],
                'mode' => $data['mode'],
                'rate_or_amount' => $data['rate_or_amount'],
                'base_amount' => $data['base_amount'] ?: $data['rate_or_amount'],
                'process_ref' => $data['process_ref'] ?? null,
                'writ_id' => $data['writ_id'] ?? null,
                'reference_date' => $data['reference_date'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            if ($createAnother) {
                $this->reset(['case_type_id', 'name', 'base_amount', 'rate_or_amount', 'process_ref', 'writ_id', 'bank_account_id', 'notes']);
                $this->mode = 'percent';
                $this->reference_date = \App\Support\BusinessDate::today();
                $this->resetValidation();
                $this->formSuccessMessage = 'Comissão registrada.';

                return null;
            }
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
            'writs' => \App\Domains\Writs\Models\Writ::query()->with('assignors.contact:id,name')->orderByDesc('id')->limit(200)->get(['id', 'process_number', 'assignor_name']),
        ];
    }
}; ?>

<x-slot name="header">Registrar comissão · {{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    <x-back-button :href="route('brokers.commissions.index', $broker)" />
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
                            <a href="{{ route('brokers.tipos-caso.index') }}" class="font-semibold text-primary-500 hover:text-primary-600">Cadastrar tipos de caso</a>
                        </p>
                    @endif
                </div>
                <x-fx.input label="Nome *" wire:model="name" placeholder="Ex.: cliente ou processo" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Modo *</label>
                    <select wire:model.live="mode" class="fx-form-field">
                        <option value="percent">Percentual</option>
                        <option value="fixed">Valor fixo</option>
                    </select>
                </div>
                @if ($mode === 'percent')
                    <x-fx.input label="Percentual *" type="text" x-decimal="2" wire:model="rate_or_amount" wire:key="rate-percent" />
                @else
                    <x-fx.input label="Valor fixo *" type="text" x-money wire:model="rate_or_amount" wire:key="rate-fixed" />
                @endif
                @if ($mode === 'percent')
                    <x-fx.input label="Base de cálculo *" type="text" x-money wire:model="base_amount" />
                @endif
                <x-fx.input label="Processo (número)" wire:model="process_ref" x-process-number placeholder="Ex.: 0001234-56.2024.8.10.0001" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Requisitório</label>
                    <select wire:model="writ_id" class="fx-form-field">
                        <option value="">— opcional —</option>
                        @foreach ($writs as $writ)
                            <option value="{{ $writ->id }}">#{{ $writ->id }} · {{ $writ->process_number }} · {{ $writ->beneficiaryName() }}</option>
                        @endforeach
                    </select>
                </div>
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
            @if ($formSuccessMessage)
                <div class="rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success" data-test="form-success-message">{{ $formSuccessMessage }}</div>
            @endif
            <div class="flex flex-wrap gap-xs">
                <a href="{{ route('brokers.commissions.index', $broker) }}" class="fx-btn fx-btn--text">Cancelar</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="fx-btn fx-btn--primary">Salvar</button>
                <button type="button" wire:click="saveAndCreateAnother" wire:loading.attr="disabled" wire:target="save,saveAndCreateAnother" class="fx-btn fx-btn--standard" data-test="save-and-create-another">Salvar e novo</button>
            </div>
        </form>
    </x-fx.card>
</div>

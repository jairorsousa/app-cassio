<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\RecurringTransaction;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $type = 'expense';
    public string $description = '';
    public string $amount = '';
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public string $frequency = 'monthly';
    public ?int $day_of_month = null;
    public string $start_date = '';
    public string $end_date = '';
    public string $rec_status = 'active';

    public function mount(): void
    {
        $this->start_date = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:income,expense',
            'description' => 'required|string|max:200',
            'amount' => 'required|numeric|min:0.01',
            'category_id' => 'nullable|exists:categories,id',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'frequency' => 'required|in:daily,weekly,monthly,yearly',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'rec_status' => 'required|in:active,paused,finished',
        ];
    }

    public function edit(int $id): void
    {
        $r = RecurringTransaction::findOrFail($id);
        $this->editingId = $r->id;
        $this->type = $r->type;
        $this->description = $r->description;
        $this->amount = (string) $r->amount;
        $this->category_id = $r->category_id;
        $this->bank_account_id = $r->bank_account_id;
        $this->frequency = $r->frequency;
        $this->day_of_month = $r->day_of_month;
        $this->start_date = $r->start_date->format('Y-m-d');
        $this->end_date = $r->end_date?->format('Y-m-d') ?? '';
        $this->rec_status = $r->status;
    }

    public function save(): void
    {
        $data = $this->validate();
        $payload = [
            'type' => $data['type'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'category_id' => $data['category_id'],
            'bank_account_id' => $data['bank_account_id'],
            'frequency' => $data['frequency'],
            'day_of_month' => $data['day_of_month'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?: null,
            'status' => $data['rec_status'],
        ];

        if ($this->editingId) {
            RecurringTransaction::find($this->editingId)?->update($payload);
        } else {
            RecurringTransaction::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Recorrência salva.');
    }

    public function pause(int $id): void
    {
        RecurringTransaction::find($id)?->update(['status' => 'paused']);
    }

    public function resume(int $id): void
    {
        RecurringTransaction::find($id)?->update(['status' => 'active']);
    }

    public function finish(int $id): void
    {
        RecurringTransaction::find($id)?->update(['status' => 'finished']);
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'description', 'amount', 'category_id', 'bank_account_id', 'day_of_month', 'end_date']);
        $this->type = 'expense';
        $this->frequency = 'monthly';
        $this->rec_status = 'active';
        $this->start_date = now()->format('Y-m-d');
    }

    public function with(): array
    {
        return [
            'recurrings' => RecurringTransaction::with(['category', 'bankAccount'])->orderBy('description')->get(),
            'categories' => Category::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-md">
    <x-banking.subnav />

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if ($recurrings->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma recorrência configurada.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Descrição</th>
                        <th class="text-left">Frequência</th>
                        <th class="text-right">Valor</th>
                        <th class="text-left">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recurrings as $r)
                        <tr>
                            <td>{{ $r->description }}</td>
                            <td class="text-xxs">{{ ['daily'=>'Diária','weekly'=>'Semanal','monthly'=>'Mensal','yearly'=>'Anual'][$r->frequency] }}</td>
                            <td class="text-right {{ $r->type === 'income' ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($r->amount, 2, ',', '.') }}
                            </td>
                            <td><span class="fx-badge">{{ ['active'=>'Ativa','paused'=>'Pausada','finished'=>'Encerrada'][$r->status] }}</span></td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $r->id }})">Editar</button>
                                @if ($r->status === 'active')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="pause({{ $r->id }})">Pausar</button>
                                @elseif ($r->status === 'paused')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="resume({{ $r->id }})">Retomar</button>
                                @endif
                                @if ($r->status !== 'finished')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="finish({{ $r->id }})">Encerrar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} recorrência</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model="type" class="fx-form-field">
                    <option value="expense">Despesa</option>
                    <option value="income">Receita</option>
                </select>
            </div>
            <x-fx.input label="Descrição" wire:model="description" required />
            <x-fx.input label="Valor" type="text" x-money wire:model="amount" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Frequência</label>
                <select wire:model="frequency" class="fx-form-field">
                    <option value="daily">Diária</option>
                    <option value="weekly">Semanal</option>
                    <option value="monthly">Mensal</option>
                    <option value="yearly">Anual</option>
                </select>
            </div>
            <x-fx.input label="Dia do mês (mensal)" type="number" min="1" max="31" wire:model="day_of_month" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Categoria</label>
                <select wire:model="category_id" class="fx-form-field">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta</label>
                <select wire:model="bank_account_id" class="fx-form-field">
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Início" type="date" wire:model="start_date" />
                <x-fx.input label="Fim" type="date" wire:model="end_date" />
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model="rec_status" class="fx-form-field">
                    <option value="active">Ativa</option>
                    <option value="paused">Pausada</option>
                    <option value="finished">Encerrada</option>
                </select>
            </div>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
    </x-fx.card>
</div>
</div>

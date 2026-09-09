<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\RecurringTransaction;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showFormModal = false;

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
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
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

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['showFormModal', 'editingId', 'description', 'amount', 'category_id', 'bank_account_id', 'day_of_month', 'end_date']);
        $this->type = 'expense';
        $this->frequency = 'monthly';
        $this->rec_status = 'active';
        $this->start_date = now()->format('Y-m-d');
        $this->resetValidation();
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

    <x-fx.card>
        <div class="mb-space-4 flex items-center justify-between">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Recorrências</h3>
            <x-jr.button type="button" size="sm" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Nova recorrência
            </x-jr.button>
        </div>

        @if ($recurrings->isEmpty())
            <x-fx.empty-state
                icon="🔄"
                title="Nenhuma recorrência configurada"
                description="Cadastre receitas ou despesas recorrentes para automatizar seus lançamentos.">
                <x-jr.button type="button" class="mt-space-4" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Nova recorrência
                </x-jr.button>
            </x-fx.empty-state>
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

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Recorrência' : 'Nova Recorrência' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">autorenew</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados da recorrência</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                                        <select wire:model="type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="expense">Despesa</option>
                                            <option value="income">Receita</option>
                                        </select>
                                        @error('type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div class="md:col-span-2">
                                        <x-jr.input label="Descrição *" icon="description" name="description" wire:model="description" required />
                                    </div>
                                    <x-jr.input label="Valor *" icon="payments" name="amount" wire:model="amount" x-money required />
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Categoria</label>
                                        <select wire:model="category_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="">Sem categoria</option>
                                            @foreach ($categories as $categoryOption)
                                                <option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('category_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta</label>
                                        <select wire:model="bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="">Nenhuma</option>
                                            @foreach ($accounts as $accountOption)
                                                <option value="{{ $accountOption->id }}">{{ $accountOption->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('bank_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">calendar_month</span>
                                    <h4 class="text-base font-bold text-mono-900">Periodicidade</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Frequência *</label>
                                        <select wire:model.live="frequency" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="daily">Diária</option>
                                            <option value="weekly">Semanal</option>
                                            <option value="monthly">Mensal</option>
                                            <option value="yearly">Anual</option>
                                        </select>
                                        @error('frequency') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                    @if ($frequency === 'monthly')
                                        <x-jr.input label="Dia do mês" icon="today" name="day_of_month" type="number" min="1" max="31" wire:model="day_of_month" />
                                    @endif
                                    <x-jr.input label="Início *" icon="event" name="start_date" type="date" wire:model="start_date" required />
                                    <x-jr.input label="Fim" icon="event_busy" name="end_date" type="date" wire:model="end_date" />
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">tune</span>
                                    <h4 class="text-base font-bold text-mono-900">Status</h4>
                                </div>
                                <div class="max-w-sm">
                                    <label class="mb-2 block text-sm font-medium text-mono-600">Situação *</label>
                                    <select wire:model="rec_status" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                        <option value="active">Ativa</option>
                                        <option value="paused">Pausada</option>
                                        <option value="finished">Encerrada</option>
                                    </select>
                                    @error('rec_status') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            {{ $editingId ? 'Salvar Recorrência' : 'Criar Recorrência' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

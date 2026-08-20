<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public ?int $editingId = null;
    public bool $showFormModal = false;
    public string $contribDate = '';
    public string $amount = '';
    public string $contribStatus = 'done';
    public ?int $bank_account_id = null;
    public string $purpose = '';
    public string $contribNotes = '';

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
        $this->contribDate = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'contribDate' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'contribStatus' => 'required|in:pending,done',
            'bank_account_id' => $this->contribStatus === 'done'
                ? 'required|exists:bank_accounts,id'
                : 'nullable|exists:bank_accounts,id',
            'purpose' => 'nullable|string|max:200',
            'contribNotes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Informe a conta de origem para o aporte entrar no caixa.',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $c = $this->partnership->contributions()->findOrFail($id);
        $this->editingId = $c->id;
        $this->contribDate = $c->date->format('Y-m-d');
        $this->amount = (string) $c->amount;
        $this->contribStatus = $c->status;
        $this->bank_account_id = $c->bank_account_id;
        $this->purpose = (string) $c->purpose;
        $this->contribNotes = (string) $c->notes;
        $this->showFormModal = true;
    }

    public function markDone(int $id): void
    {
        $contribution = $this->partnership->contributions()->find($id);
        if (! $contribution) {
            return;
        }

        if (! $contribution->bank_account_id) {
            $this->edit($id);
            session()->flash('error', 'Informe a conta de origem antes de realizar o aporte.');

            return;
        }

        $contribution->update(['status' => 'done']);
        session()->flash('status', 'Aporte realizado.');
    }

    public function save(): void
    {
        $data = $this->validate();
        $payload = [
            'partnership_id' => $this->partnership->id,
            'date' => $data['contribDate'],
            'amount' => $data['amount'],
            'status' => $data['contribStatus'],
            'bank_account_id' => $data['bank_account_id'],
            'purpose' => $data['purpose'],
            'notes' => $data['contribNotes'],
        ];

        if ($this->editingId) {
            $this->partnership->contributions()->find($this->editingId)?->update($payload);
        } else {
            PartnershipContribution::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Aporte salvo.');
    }

    public function delete(int $id): void
    {
        $this->partnership->contributions()->find($id)?->delete();
        session()->flash('status', 'Aporte excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showFormModal', 'amount', 'bank_account_id', 'purpose', 'contribNotes']);
        $this->contribStatus = 'done';
        $this->contribDate = now()->format('Y-m-d');
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'contributions' => $this->partnership->contributions()->with('bankAccount')->orderByDesc('date')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }} · Aportes</x-slot>

<div class="flex flex-col gap-md">
    <x-partnership.subnav :partnership="$partnership" />

    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-mono-900">Aportes</h2>
            <p class="mt-1 text-sm text-mono-600">Acompanhe aportes realizados e compromissos futuros.</p>
        </div>
        <button type="button" class="fx-btn fx-btn--primary self-start sm:self-auto" wire:click="create">
            <span class="material-icons-outlined text-[18px]">add</span>
            Novo aporte
        </button>
    </div>

    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

    @if ($contributions->isEmpty())
        <x-jr.empty-state icon="savings" title="Nenhum aporte cadastrado" description="Registre o primeiro aporte realizado ou previsto para esta sociedade." />
    @else
        <x-fx.card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="fx-table text-sm">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Status</th>
                            <th>Finalidade</th>
                            <th>Conta</th>
                            <th class="text-right">Valor</th>
                            <th class="w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contributions as $c)
                            <tr>
                                <td class="whitespace-nowrap font-mono">{{ $c->date->format('d/m/Y') }}</td>
                                <td><span class="fx-badge fx-badge--{{ $c->status === 'done' ? 'up' : 'neutral' }}">{{ $c->status === 'done' ? 'Realizado' : 'Pendente' }}</span></td>
                                <td>{{ $c->purpose ?: '—' }}</td>
                                <td>{{ $c->bankAccount?->name ?? '—' }}</td>
                                <td class="text-right whitespace-nowrap font-mono font-semibold">R$ {{ number_format((float) $c->amount, 2, ',', '.') }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-xxs">
                                        @if ($c->status === 'pending')
                                            <button type="button" class="fx-btn fx-btn--standard fx-btn--sm" wire:click="markDone({{ $c->id }})">Realizar</button>
                                        @endif
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9" wire:click="edit({{ $c->id }})" title="Editar aporte" aria-label="Editar aporte">
                                            <span class="material-icons-outlined text-[18px]">edit</span>
                                        </button>
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $c->id }})" wire:confirm="Excluir aporte? O lançamento no caixa também será removido." title="Excluir aporte" aria-label="Excluir aporte">
                                            <span class="material-icons-outlined text-[18px]">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fx.card>
    @endif

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar aporte' : 'Novo aporte' }}</h3>
                        <p class="text-xs text-mono-600">{{ $partnership->name }}</p>
                    </div>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-jr.input label="Data" icon="event" type="date" name="contribDate" wire:model="contribDate" />
                            <x-jr.input label="Valor" icon="attach_money" type="text" name="amount" x-money wire:model="amount" />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Status</label>
                                <select wire:model.live="contribStatus" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                    <option value="done">Realizado</option>
                                    <option value="pending">Pendente</option>
                                </select>
                                @error('contribStatus')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Conta de origem @if ($contribStatus === 'done')<span class="text-error">*</span>@endif</label>
                                <select wire:model="bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" @if ($contribStatus === 'done') required @endif>
                                    <option value="">Selecione</option>
                                    @foreach ($accounts as $a)
                                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                                    @endforeach
                                </select>
                                @error('bank_account_id')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="md:col-span-2">
                                <x-jr.input label="Finalidade" icon="flag" name="purpose" wire:model="purpose" />
                            </div>

                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-mono-600">Notas</label>
                                <textarea wire:model="contribNotes" class="min-h-24 w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" rows="3"></textarea>
                                @error('contribNotes')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar aporte</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

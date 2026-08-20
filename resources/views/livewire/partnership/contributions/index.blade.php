<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public ?int $editingId = null;
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

    public function edit(int $id): void
    {
        $c = PartnershipContribution::findOrFail($id);
        $this->editingId = $c->id;
        $this->contribDate = $c->date->format('Y-m-d');
        $this->amount = (string) $c->amount;
        $this->contribStatus = $c->status;
        $this->bank_account_id = $c->bank_account_id;
        $this->purpose = (string) $c->purpose;
        $this->contribNotes = (string) $c->notes;
    }

    public function markDone(int $id): void
    {
        $contribution = PartnershipContribution::find($id);
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
            PartnershipContribution::find($this->editingId)?->update($payload);
        } else {
            PartnershipContribution::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Aporte salvo.');
    }

    public function delete(int $id): void
    {
        PartnershipContribution::find($id)?->delete();
        session()->flash('status', 'Aporte excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'amount', 'bank_account_id', 'purpose', 'contribNotes']);
        $this->contribStatus = 'done';
        $this->contribDate = now()->format('Y-m-d');
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
        <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
        @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

        @if ($contributions->isEmpty())
            <div class="text-sm text-mono-600">Nenhum aporte.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Finalidade</th>
                        <th class="text-left">Conta</th>
                        <th class="text-right">Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contributions as $c)
                        <tr>
                            <td>{{ $c->date->format('d/m/Y') }}</td>
                            <td><span class="fx-badge fx-badge--{{ $c->status === 'done' ? 'up' : 'neutral' }}">{{ $c->status === 'done' ? 'Realizado' : 'Pendente' }}</span></td>
                            <td>{{ $c->purpose ?? '—' }}</td>
                            <td>{{ $c->bankAccount?->name ?? '—' }}</td>
                            <td class="text-right font-semibold">R$ {{ number_format((float) $c->amount, 2, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($c->status === 'pending')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="markDone({{ $c->id }})">Realizar</button>
                                @endif
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $c->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $c->id }})" wire:confirm="Excluir aporte? O lançamento no caixa também será removido.">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        </x-fx.card>

        <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Novo' }} aporte</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Data" type="date" wire:model="contribDate" />
            <x-fx.input label="Valor" type="text" x-money wire:model="amount" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model.live="contribStatus" class="fx-form-field">
                    <option value="done">Realizado</option>
                    <option value="pending">Pendente</option>
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">
                    Conta de origem @if ($contribStatus === 'done')<span class="text-system-down">*</span>@endif
                </label>
                <select wire:model="bank_account_id" class="fx-form-field" @if ($contribStatus === 'done') required @endif>
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
                @error('bank_account_id')
                    <div class="text-xxs text-system-down mt-xxxs">{{ $message }}</div>
                @enderror
            </div>
            <x-fx.input label="Finalidade" wire:model="purpose" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="contribNotes" class="fx-form-field" rows="2"></textarea>
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

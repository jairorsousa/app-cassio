<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipDistribution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public ?int $editingId = null;
    public string $distDate = '';
    public string $amount = '';
    public ?int $bank_account_id = null;
    public string $source = '';
    public string $distNotes = '';

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
        $this->distDate = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'distDate' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'source' => 'nullable|string|max:200',
            'distNotes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $d = PartnershipDistribution::findOrFail($id);
        $this->editingId = $d->id;
        $this->distDate = $d->date->format('Y-m-d');
        $this->amount = (string) $d->amount;
        $this->bank_account_id = $d->bank_account_id;
        $this->source = (string) $d->source;
        $this->distNotes = (string) $d->notes;
    }

    public function save(): void
    {
        $data = $this->validate();
        $payload = [
            'partnership_id' => $this->partnership->id,
            'date' => $data['distDate'],
            'amount' => $data['amount'],
            'bank_account_id' => $data['bank_account_id'],
            'source' => $data['source'],
            'notes' => $data['distNotes'],
        ];

        if ($this->editingId) {
            PartnershipDistribution::find($this->editingId)?->update($payload);
        } else {
            PartnershipDistribution::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Distribuição salva.');
    }

    public function delete(int $id): void
    {
        PartnershipDistribution::find($id)?->delete();
        session()->flash('status', 'Distribuição excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'amount', 'bank_account_id', 'source', 'distNotes']);
        $this->distDate = now()->format('Y-m-d');
    }

    public function with(): array
    {
        return [
            'distributions' => $this->partnership->distributions()->with('bankAccount')->orderByDesc('date')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }} · Distribuições</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($distributions->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma distribuição.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Origem</th>
                        <th class="text-left">Conta</th>
                        <th class="text-right">Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($distributions as $d)
                        <tr>
                            <td>{{ $d->date->format('d/m/Y') }}</td>
                            <td>{{ $d->source ?? '—' }}</td>
                            <td>{{ $d->bankAccount?->name ?? '—' }}</td>
                            <td class="text-right font-semibold text-system-up">R$ {{ number_format((float) $d->amount, 2, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $d->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $d->id }})" wire:confirm="Excluir distribuição?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} distribuição</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Data" type="date" wire:model="distDate" />
            <x-fx.input label="Valor" type="text" x-money wire:model="amount" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta de destino</label>
                <select wire:model="bank_account_id" class="fx-form-field">
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-fx.input label="Origem (ex: lucro Q1)" wire:model="source" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="distNotes" class="fx-form-field" rows="2"></textarea>
            </div>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
        <a href="{{ route('partnership.show', $partnership) }}" class="fx-btn fx-btn--text fx-btn--sm mt-sm">← Voltar</a>
    </x-fx.card>
</div>

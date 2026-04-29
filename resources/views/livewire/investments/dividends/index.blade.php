<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetDividend;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public ?int $asset_id = null;
    public string $payment_date = '';
    public string $type = 'dividend';
    public string $unit_amount = '';
    public string $quantity = '';
    public ?int $bank_account_id = null;
    public string $divNotes = '';

    public function mount(): void
    {
        $this->payment_date = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'payment_date' => 'required|date',
            'type' => 'required|in:dividend,jcp,fii',
            'unit_amount' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'divNotes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $d = AssetDividend::findOrFail($id);
        $this->editingId = $d->id;
        $this->asset_id = $d->asset_id;
        $this->payment_date = $d->payment_date->format('Y-m-d');
        $this->type = $d->type;
        $this->unit_amount = (string) $d->unit_amount;
        $this->quantity = (string) $d->quantity;
        $this->bank_account_id = $d->bank_account_id;
        $this->divNotes = (string) $d->notes;
    }

    public function save(): void
    {
        $data = $this->validate();
        $total = round((float) $data['unit_amount'] * (float) $data['quantity'], 2);
        $payload = [
            'asset_id' => $data['asset_id'],
            'payment_date' => $data['payment_date'],
            'type' => $data['type'],
            'unit_amount' => $data['unit_amount'],
            'quantity' => $data['quantity'],
            'total' => $total,
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['divNotes'],
        ];

        if ($this->editingId) {
            AssetDividend::find($this->editingId)?->update($payload);
        } else {
            AssetDividend::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Provento salvo.');
    }

    public function delete(int $id): void
    {
        AssetDividend::find($id)?->delete();
        session()->flash('status', 'Provento excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'asset_id', 'unit_amount', 'quantity', 'bank_account_id', 'divNotes']);
        $this->type = 'dividend';
        $this->payment_date = now()->format('Y-m-d');
    }

    public function with(): array
    {
        return [
            'dividends' => AssetDividend::with('asset', 'bankAccount')->orderByDesc('payment_date')->paginate(25),
            'assets' => Asset::active()->orderBy('ticker')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Proventos</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($dividends->isEmpty())
            <div class="text-sm text-mono-600">Sem proventos lançados.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Ativo</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right">R$/un</th>
                        <th class="text-right">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dividends as $d)
                        <tr>
                            <td>{{ $d->payment_date->format('d/m/Y') }}</td>
                            <td class="font-semibold">{{ $d->asset?->ticker }}</td>
                            <td>{{ \App\Domains\Investments\Models\AssetDividend::TYPE_LABELS[$d->type] }}</td>
                            <td class="text-right">{{ number_format((float) $d->quantity, 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $d->unit_amount, 6, ',', '.') }}</td>
                            <td class="text-right text-system-up font-semibold">R$ {{ number_format((float) $d->total, 2, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $d->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $d->id }})" wire:confirm="Excluir provento?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-sm">{{ $dividends->links() }}</div>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Novo' }} provento</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Ativo</label>
                <select wire:model="asset_id" class="fx-form-field" required>
                    <option value="">—</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }}</option>
                    @endforeach
                </select>
            </div>
            <x-fx.input label="Data do pagamento" type="date" wire:model="payment_date" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model="type" class="fx-form-field">
                    <option value="dividend">Dividendo</option>
                    <option value="jcp">JCP</option>
                    <option value="fii">Rendimento FII</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Quantidade" type="number" step="0.000001" wire:model="quantity" />
                <x-fx.input label="Valor por unidade" type="number" step="0.000001" wire:model="unit_amount" />
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta de crédito</label>
                <select wire:model="bank_account_id" class="fx-form-field">
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="divNotes" class="fx-form-field" rows="2"></textarea>
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

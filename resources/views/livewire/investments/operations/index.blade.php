<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $assetFilter = '';
    #[Url]
    public string $typeFilter = '';

    public ?int $editingId = null;
    public ?int $asset_id = null;
    public string $opDate = '';
    public string $opType = 'buy';
    public string $quantity = '';
    public string $unit_price = '';
    public string $fees = '0';
    public ?int $bank_account_id = null;
    public string $opNotes = '';

    public function mount(): void
    {
        $this->opDate = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'opDate' => 'required|date',
            'opType' => 'required|in:buy,sell',
            'quantity' => 'required|numeric|min:0.000001',
            'unit_price' => 'required|numeric|min:0',
            'fees' => 'required|numeric|min:0',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'opNotes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $op = AssetOperation::findOrFail($id);
        $this->editingId = $op->id;
        $this->asset_id = $op->asset_id;
        $this->opDate = $op->date->format('Y-m-d');
        $this->opType = $op->type;
        $this->quantity = (string) $op->quantity;
        $this->unit_price = (string) $op->unit_price;
        $this->fees = (string) $op->fees;
        $this->bank_account_id = $op->bank_account_id;
        $this->opNotes = (string) $op->notes;
    }

    public function save(): void
    {
        $data = $this->validate();
        $qty = (float) $data['quantity'];
        $unit = (float) $data['unit_price'];
        $fees = (float) $data['fees'];
        $total = round($qty * $unit + ($data['opType'] === 'buy' ? $fees : -$fees), 2);

        $payload = [
            'asset_id' => $data['asset_id'],
            'date' => $data['opDate'],
            'type' => $data['opType'],
            'quantity' => $qty,
            'unit_price' => $unit,
            'fees' => $fees,
            'total' => $total,
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['opNotes'],
        ];

        if ($this->editingId) {
            AssetOperation::find($this->editingId)?->update($payload);
        } else {
            AssetOperation::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Operação salva.');
    }

    public function delete(int $id): void
    {
        AssetOperation::find($id)?->delete();
        $asset = Asset::find(AssetOperation::withTrashed()->find($id)?->asset_id);
        if ($asset) {
            app(\App\Domains\Investments\Services\AssetPositionService::class)->recalculate($asset);
        }
        session()->flash('status', 'Operação excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'asset_id', 'quantity', 'unit_price', 'fees', 'bank_account_id', 'opNotes']);
        $this->opType = 'buy';
        $this->fees = '0';
        $this->opDate = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $q = AssetOperation::with('asset', 'bankAccount');
        if ($this->assetFilter) $q->where('asset_id', $this->assetFilter);
        if ($this->typeFilter) $q->where('type', $this->typeFilter);

        return [
            'operations' => $q->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'assets' => Asset::active()->orderBy('ticker')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Operações</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        <div class="grid grid-cols-2 gap-xs mb-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Ativo</label>
                <select wire:model.live="assetFilter" class="fx-form-field">
                    <option value="">Todos</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model.live="typeFilter" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="buy">Compra</option>
                    <option value="sell">Venda</option>
                </select>
            </div>
        </div>

        @if ($operations->isEmpty())
            <div class="text-sm text-mono-600">Sem operações.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Ativo</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right">Preço</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">PnL realizado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operations as $op)
                        <tr>
                            <td>{{ $op->date->format('d/m/Y') }}</td>
                            <td class="font-semibold">{{ $op->asset?->ticker }}</td>
                            <td>
                                <span class="fx-badge fx-badge--{{ $op->type === 'buy' ? 'down' : 'up' }}">
                                    {{ $op->type === 'buy' ? 'Compra' : 'Venda' }}
                                </span>
                            </td>
                            <td class="text-right">{{ number_format((float) $op->quantity, 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $op->unit_price, 4, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $op->total, 2, ',', '.') }}</td>
                            <td class="text-right {{ ((float) $op->realized_pnl) >= 0 ? 'text-system-up' : 'text-system-down' }}">
                                {{ $op->realized_pnl !== null ? 'R$ '.number_format((float) $op->realized_pnl, 2, ',', '.') : '—' }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $op->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $op->id }})" wire:confirm="Excluir operação?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-sm">{{ $operations->links() }}</div>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} operação</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Ativo</label>
                <select wire:model="asset_id" class="fx-form-field" required>
                    <option value="">—</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }} — {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Data" type="date" wire:model="opDate" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                    <select wire:model="opType" class="fx-form-field">
                        <option value="buy">Compra</option>
                        <option value="sell">Venda</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Quantidade" type="number" step="0.000001" wire:model="quantity" />
                <x-fx.input label="Preço unitário" type="number" step="0.0001" wire:model="unit_price" />
            </div>
            <x-fx.input label="Taxas/corretagem" type="number" step="0.01" wire:model="fees" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta liquidação</label>
                <select wire:model="bank_account_id" class="fx-form-field">
                    <option value="">— nenhuma —</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="opNotes" class="fx-form-field" rows="2"></textarea>
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

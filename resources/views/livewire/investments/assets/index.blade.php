<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $ticker = '';
    public string $name = '';
    public ?int $asset_class_id = null;
    public string $sector = '';
    public string $notes = '';
    public bool $assetStatus = true;

    public function rules(): array
    {
        return [
            'ticker' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'asset_class_id' => 'required|exists:asset_classes,id',
            'sector' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
            'assetStatus' => 'boolean',
        ];
    }

    public function edit(int $id): void
    {
        $a = Asset::findOrFail($id);
        $this->editingId = $a->id;
        $this->ticker = $a->ticker;
        $this->name = $a->name;
        $this->asset_class_id = $a->asset_class_id;
        $this->sector = (string) $a->sector;
        $this->notes = (string) $a->notes;
        $this->assetStatus = (bool) $a->status;
    }

    public function save(): void
    {
        $data = $this->validate();
        $payload = [
            'ticker' => $data['ticker'],
            'name' => $data['name'],
            'asset_class_id' => $data['asset_class_id'],
            'sector' => $data['sector'],
            'notes' => $data['notes'],
            'status' => $data['assetStatus'],
        ];

        if ($this->editingId) {
            Asset::find($this->editingId)?->update($payload);
        } else {
            Asset::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Ativo salvo.');
    }

    public function delete(int $id): void
    {
        Asset::find($id)?->delete();
        session()->flash('status', 'Ativo excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'ticker', 'name', 'asset_class_id', 'sector', 'notes']);
        $this->assetStatus = true;
    }

    public function with(): array
    {
        return [
            'assets' => Asset::with('assetClass', 'position')->orderBy('ticker')->get(),
            'classes' => AssetClass::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Ativos</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
        @if ($assets->isEmpty())
            <div class="text-sm text-mono-600">Nenhum ativo cadastrado.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Ticker</th>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Classe</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right">Preço médio</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr>
                            <td class="font-semibold">{{ $a->ticker }}</td>
                            <td>{{ $a->name }} @unless($a->status)<span class="text-xxs text-mono-600">(inativo)</span>@endunless</td>
                            <td>{{ $a->assetClass?->name }}</td>
                            <td class="text-right">{{ number_format((float) ($a->position?->quantity ?? 0), 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) ($a->position?->average_price ?? 0), 4, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $a->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $a->id }})" wire:confirm="Excluir ativo?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Novo' }} ativo</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Ticker" wire:model="ticker" placeholder="PETR4, HGLG11..." required />
            <x-fx.input label="Nome" wire:model="name" required />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Classe</label>
                <select wire:model="asset_class_id" class="fx-form-field" required>
                    <option value="">— selecionar —</option>
                    @foreach ($classes as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-fx.input label="Setor" wire:model="sector" />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="notes" class="fx-form-field" rows="2"></textarea>
            </div>
            <label class="flex items-center gap-xs text-sm">
                <input type="checkbox" wire:model="assetStatus" /> Ativo
            </label>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
    </x-fx.card>
</div>

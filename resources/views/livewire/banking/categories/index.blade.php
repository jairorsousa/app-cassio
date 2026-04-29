<?php

use App\Domains\Banking\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'expense';
    public ?int $parent_id = null;
    public bool $status = true;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'type' => 'required|in:income,expense',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'boolean',
        ];
    }

    public function edit(int $id): void
    {
        $cat = Category::findOrFail($id);
        $this->editingId = $cat->id;
        $this->name = $cat->name;
        $this->type = $cat->type;
        $this->parent_id = $cat->parent_id;
        $this->status = (bool) $cat->status;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            Category::find($this->editingId)?->update($data);
        } else {
            Category::create($data);
        }

        $this->reset(['editingId', 'name', 'type', 'parent_id', 'status']);
        $this->status = true;
        $this->type = 'expense';
        session()->flash('status', 'Categoria salva.');
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'type', 'parent_id', 'status']);
        $this->status = true;
        $this->type = 'expense';
    }

    public function with(): array
    {
        return [
            'roots' => Category::with('children')->roots()->orderBy('type')->orderBy('name')->get(),
            'parents' => Category::roots()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro · Categorias</x-slot>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        <h3 class="text-md font-semibold mb-sm">Árvore</h3>
        @if ($roots->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma categoria cadastrada.</div>
        @else
            <ul class="flex flex-col gap-xxs">
                @foreach ($roots as $root)
                    <li class="border border-mono-100 rounded-md p-xs">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-xs">
                                <span class="fx-badge fx-badge--{{ $root->type === 'income' ? 'up' : 'down' }}">{{ $root->type === 'income' ? 'Receita' : 'Despesa' }}</span>
                                <span class="font-medium">{{ $root->name }}</span>
                                @unless ($root->status)<span class="text-xxs text-mono-600">(inativa)</span>@endunless
                            </div>
                            <button type="button" class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $root->id }})">Editar</button>
                        </div>
                        @if ($root->children->isNotEmpty())
                            <ul class="mt-xxs ml-md flex flex-col gap-xxxs">
                                @foreach ($root->children as $child)
                                    <li class="flex justify-between items-center py-xxxs">
                                        <span class="text-sm">{{ $child->name }}</span>
                                        <button type="button" class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $child->id }})">Editar</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} categoria</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Nome" wire:model="name" required />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                <select wire:model="type" class="fx-form-field">
                    <option value="expense">Despesa</option>
                    <option value="income">Receita</option>
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Categoria pai</label>
                <select wire:model="parent_id" class="fx-form-field">
                    <option value="">— nenhuma (raiz) —</option>
                    @foreach ($parents as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-xs text-sm">
                <input type="checkbox" wire:model="status" /> Ativa
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

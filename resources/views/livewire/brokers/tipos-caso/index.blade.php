<?php

use App\Domains\Brokers\Models\CaseType;
use App\Support\ColumnSorter;
use App\Support\Concerns\SortsColumns;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use SortsColumns;

    public string $name = '';
    public bool $status = true;
    public ?int $editingId = null;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'status' => 'boolean',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            CaseType::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Tipo de caso atualizado.');
        } else {
            CaseType::create($data);
            session()->flash('status', 'Tipo de caso cadastrado.');
        }

        $this->reset(['name', 'status', 'editingId']);
    }

    public function edit(int $id): void
    {
        $type = CaseType::findOrFail($id);
        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->status = $type->status;
    }

    public function cancel(): void
    {
        $this->reset(['name', 'status', 'editingId']);
    }

    public function mount(): void
    {
        $this->loadSort();
    }

    protected function sortListKey(): string
    {
        return 'case_types';
    }

    public function with(): array
    {
        [$by, $dir] = $this->sortState();

        return [
            'caseTypes' => ColumnSorter::sort(CaseType::orderBy('name')->get(), $by, $dir, [
                'name' => fn ($t) => $t->name,
                'status' => fn ($t) => $t->status ? 'Ativo' : 'Inativo',
            ], [[fn ($t) => $t->id, 'asc']]),
        ];
    }
}; ?>

<x-slot name="header">Tipos de Caso</x-slot>

<div class="flex flex-col gap-md">
    <x-brokers.subnav />

    <x-fx.alert variant="info">
        Cadastre aqui os tipos de caso usados nas comissões dos corretores — por exemplo: Previdenciário, Trabalhista, Cível ou Requisitório.
    </x-fx.alert>

<div class="flex flex-col md:flex-row gap-md items-start">
    <div class="w-full md:w-1/3">
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Novo' }} Tipo de Caso</h3>
            
            @if (session('status'))
                <x-fx.alert variant="success" class="mb-sm">{{ session('status') }}</x-fx.alert>
            @endif

            <form wire:submit="save" class="flex flex-col gap-sm">
                <x-fx.input label="Nome *" wire:model="name" placeholder="Ex.: Previdenciário" />
                
                <label class="flex items-center gap-xxs text-sm">
                    <input type="checkbox" wire:model="status" class="rounded border-mono-300 text-primary-500">
                    Ativo
                </label>
                
                <div class="flex gap-xs mt-xs">
                    <button type="submit" class="fx-btn fx-btn--primary fx-btn--sm">Salvar</button>
                    @if($editingId)
                        <button type="button" wire:click="cancel" class="fx-btn fx-btn--text fx-btn--sm">Cancelar</button>
                    @endif
                </div>
            </form>
        </x-fx.card>
    </div>

    <div class="w-full md:w-2/3">
        <x-fx.card>
            @if ($caseTypes->isEmpty())
                <div class="flex flex-col items-center gap-sm py-lg text-center">
                    <span class="material-icons-outlined text-4xl text-mono-300">folder_open</span>
                    <div class="text-sm font-medium text-mono-900">Nenhum tipo de caso cadastrado</div>
                    <div class="max-w-sm text-sm text-mono-600">Use o formulário ao lado para cadastrar o primeiro tipo e liberar o registro de comissões.</div>
                </div>
            @else
                <x-fx.sort-mobile list="case_types" :sort-by="$sortBy" :sort-dir="$sortDir" primary />
                <table class="fx-table w-full text-sm">
                    <thead>
                        <tr>
                            <x-fx.sort-th column="name" label="Nome" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <x-fx.sort-th column="status" label="Status" align="center" :sort-by="$sortBy" :sort-dir="$sortDir" />
                            <th class="text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($caseTypes as $ct)
                            <tr>
                                <td>{{ $ct->name }}</td>
                                <td class="text-center">
                                    <x-fx.badge :variant="$ct->status ? 'up' : 'neutral'">{{ $ct->status ? 'Ativo' : 'Inativo' }}</x-fx.badge>
                                </td>
                                <td class="text-right">
                                    <button wire:click="edit({{ $ct->id }})" class="fx-btn fx-btn--text fx-btn--sm">Editar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fx.card>
    </div>
</div>
</div>

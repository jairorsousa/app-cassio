<?php

use App\Domains\Partnership\Models\Partnership;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Partnership $partnership = null;

    public string $name = '';
    public string $cnpj = '';
    public string $participation_percentage = '0';
    public string $joined_at = '';
    public bool $partnershipStatus = true;
    public string $notes = '';

    public function mount(?Partnership $partnership = null): void
    {
        $this->joined_at = now()->format('Y-m-d');
        if ($partnership && $partnership->exists) {
            $this->partnership = $partnership;
            $this->name = $partnership->name;
            $this->cnpj = (string) $partnership->cnpj;
            $this->participation_percentage = (string) $partnership->participation_percentage;
            $this->joined_at = $partnership->joined_at?->format('Y-m-d') ?? now()->format('Y-m-d');
            $this->partnershipStatus = (bool) $partnership->status;
            $this->notes = (string) $partnership->notes;
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'cnpj' => 'nullable|string|max:20',
            'participation_percentage' => 'required|numeric|min:0|max:100',
            'joined_at' => 'nullable|date',
            'partnershipStatus' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    public function save()
    {
        $data = $this->validate();
        $payload = [
            'name' => $data['name'],
            'cnpj' => $data['cnpj'],
            'participation_percentage' => $data['participation_percentage'],
            'joined_at' => $data['joined_at'] ?: null,
            'status' => $data['partnershipStatus'],
            'notes' => $data['notes'],
        ];

        if ($this->partnership) {
            $this->partnership->update($payload);
            $p = $this->partnership;
        } else {
            $p = Partnership::create($payload);
        }

        session()->flash('status', 'Sociedade salva.');
        return $this->redirectRoute('partnership.show', $p, navigate: true);
    }
}; ?>

<x-slot name="header">{{ $partnership ? 'Editar' : 'Nova' }} sociedade</x-slot>

<x-fx.card class="max-w-2xl">
    <form wire:submit="save" class="flex flex-col gap-sm">
        <x-fx.input label="Nome" wire:model="name" required />
        <div class="grid grid-cols-2 gap-sm">
            <x-fx.input label="CNPJ" wire:model="cnpj" />
            <x-fx.input label="Participação (%)" type="number" step="0.001" min="0" max="100" wire:model="participation_percentage" />
        </div>
        <x-fx.input label="Data de entrada" type="date" wire:model="joined_at" />
        <label class="flex items-center gap-xs text-sm">
            <input type="checkbox" wire:model="partnershipStatus" /> Ativa
        </label>
        <div>
            <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
            <textarea wire:model="notes" class="fx-form-field" rows="3"></textarea>
        </div>
        <div class="flex gap-xs">
            <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
            <a href="{{ route('partnership.index') }}" class="fx-btn fx-btn--text">Cancelar</a>
        </div>
    </form>
</x-fx.card>

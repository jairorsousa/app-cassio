<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Writs\Models\Writ;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Writ $writ = null;

    public string $type = 'rpv';
    public string $process_number = '';
    public string $court = '';
    public string $debtor_entity = '';
    public string $credit_nature = '';

    public string $assignor_name = '';
    public string $assignor_document = '';
    public string $assignor_contact = '';
    public string $assignor_bank_data = '';
    public string $assignor_lawyer = '';

    public string $face_value = '0';
    public string $paid_amount = '0';
    public string $estimated_receipt_amount = '0';
    public ?int $estimated_months = null;

    public ?int $source_bank_account_id = null;
    public ?int $destination_bank_account_id = null;

    public string $notes = '';

    public function mount(?Writ $writ = null): void
    {
        if ($writ && $writ->exists) {
            $this->writ = $writ;
            foreach (['type', 'process_number', 'court', 'debtor_entity', 'credit_nature',
                'assignor_name', 'assignor_document', 'assignor_contact', 'assignor_bank_data', 'assignor_lawyer',
                'estimated_months', 'source_bank_account_id', 'destination_bank_account_id', 'notes'] as $f) {
                $this->{$f} = (string) ($writ->{$f} ?? '');
            }
            $this->face_value = (string) $writ->face_value;
            $this->paid_amount = (string) $writ->paid_amount;
            $this->estimated_receipt_amount = (string) $writ->estimated_receipt_amount;
            $this->estimated_months = $writ->estimated_months;
            $this->source_bank_account_id = $writ->source_bank_account_id;
            $this->destination_bank_account_id = $writ->destination_bank_account_id;
        }
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:rpv,precatorio',
            'process_number' => 'nullable|string|max:80',
            'court' => 'nullable|string|max:120',
            'debtor_entity' => 'nullable|string|max:120',
            'credit_nature' => 'nullable|string|max:120',
            'assignor_name' => 'nullable|string|max:200',
            'assignor_document' => 'nullable|string|max:30',
            'assignor_contact' => 'nullable|string|max:200',
            'assignor_bank_data' => 'nullable|string',
            'assignor_lawyer' => 'nullable|string|max:200',
            'face_value' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'estimated_receipt_amount' => 'required|numeric|min:0',
            'estimated_months' => 'nullable|integer|min:0',
            'source_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'destination_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function discountPreview(): float
    {
        $face = (float) $this->face_value;
        $paid = (float) $this->paid_amount;
        if ($face <= 0) return 0;
        return round((1 - $paid / $face) * 100, 2);
    }

    public function save()
    {
        $data = $this->validate();
        $face = (float) $data['face_value'];
        $paid = (float) $data['paid_amount'];
        $data['discount_percentage'] = $face > 0 ? round((1 - $paid / $face) * 100, 3) : 0;

        if ($this->writ) {
            $this->writ->update($data);
            $writ = $this->writ;
        } else {
            $writ = Writ::create(['stage' => 'negotiation'] + $data);
            \App\Domains\Writs\Models\WritStageHistory::create([
                'writ_id' => $writ->id,
                'from_stage' => null,
                'to_stage' => 'negotiation',
                'transitioned_at' => now(),
                'user_id' => auth()->id(),
            ]);
        }

        session()->flash('status', 'Requisitório salvo.');
        return $this->redirectRoute('writs.show', $writ, navigate: true);
    }

    public function with(): array
    {
        return [
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $writ ? 'Editar' : 'Novo' }} requisitório</x-slot>

<x-fx.card class="max-w-4xl">
    <form wire:submit="save" class="flex flex-col gap-md">
        <section>
            <h3 class="text-md font-semibold mb-xs">Identificação</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                    <select wire:model="type" class="fx-form-field">
                        <option value="rpv">RPV</option>
                        <option value="precatorio">Precatório</option>
                    </select>
                </div>
                <x-fx.input label="Número do processo" wire:model="process_number" />
                <x-fx.input label="Vara / Tribunal" wire:model="court" />
                <x-fx.input label="Ente devedor" wire:model="debtor_entity" placeholder="União, INSS, Estado..." />
                <x-fx.input label="Natureza do crédito" wire:model="credit_nature" placeholder="alimentar, comum..." />
            </div>
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Cedente</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <x-fx.input label="Nome" wire:model="assignor_name" />
                <x-fx.input label="CPF/CNPJ" wire:model="assignor_document" />
                <x-fx.input label="Contato" wire:model="assignor_contact" />
                <x-fx.input label="Advogado do cedente" wire:model="assignor_lawyer" />
                <div class="md:col-span-2">
                    <label class="block text-xxs text-mono-600 mb-xxxs">Dados bancários do cedente</label>
                    <textarea wire:model="assignor_bank_data" class="fx-form-field" rows="2"></textarea>
                </div>
            </div>
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Valores e Deságio</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                <x-fx.input label="Valor de face" type="number" step="0.01" wire:model.live="face_value" />
                <x-fx.input label="Valor pago ao cedente" type="number" step="0.01" wire:model.live="paid_amount" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Deságio (calculado)</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="{{ number_format($this->discountPreview(), 2, ',', '.') }}%" />
                    </div>
                </div>
                <x-fx.input label="Recebimento estimado" type="number" step="0.01" wire:model="estimated_receipt_amount" />
                <x-fx.input label="Prazo estimado (meses)" type="number" min="0" wire:model="estimated_months" />
            </div>
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Movimentação financeira</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Conta de origem (pagamento ao cedente)</label>
                    <select wire:model="source_bank_account_id" class="fx-form-field">
                        <option value="">— selecionar —</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Conta de destino (recebimento)</label>
                    <select wire:model="destination_bank_account_id" class="fx-form-field">
                        <option value="">— selecionar —</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <div>
            <label class="block text-xxs text-mono-600 mb-xxxs">Observações</label>
            <textarea wire:model="notes" class="fx-form-field" rows="3"></textarea>
        </div>

        <div class="flex gap-xs">
            <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
            <a href="{{ route('writs.kanban') }}" class="fx-btn fx-btn--text">Cancelar</a>
        </div>
    </form>
</x-fx.card>

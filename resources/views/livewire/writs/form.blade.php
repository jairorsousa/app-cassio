<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Writ $writ = null;

    public string $type = 'rpv';
    public string $process_number = '';
    public string $court = '';
    public string $debtor_entity = '';
    public string $credit_nature = '';

    public array $assignors = [['contact_id' => '', 'role' => 'parte']];

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
            $this->writ = $writ->load('assignors.contact');
            foreach (['type', 'process_number', 'court', 'debtor_entity', 'credit_nature', 'notes'] as $f) {
                $this->{$f} = (string) ($writ->{$f} ?? '');
            }
            $this->face_value = (string) $writ->face_value;
            $this->paid_amount = (string) $writ->paid_amount;
            $this->estimated_receipt_amount = (string) $writ->estimated_receipt_amount;
            $this->estimated_months = $writ->estimated_months;
            $this->source_bank_account_id = $writ->source_bank_account_id;
            $this->destination_bank_account_id = $writ->destination_bank_account_id;

            $existing = $writ->assignors->map(fn($a) => [
                'contact_id' => (string) $a->contact_id,
                'role' => $a->role,
            ])->toArray();
            $this->assignors = !empty($existing) ? $existing : [['contact_id' => '', 'role' => 'parte']];
        }
    }

    public function addAssignor(): void
    {
        $this->assignors[] = ['contact_id' => '', 'role' => 'parte'];
    }

    public function removeAssignor(int $index): void
    {
        array_splice($this->assignors, $index, 1);
        if (empty($this->assignors)) {
            $this->assignors = [['contact_id' => '', 'role' => 'parte']];
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
            'assignors' => 'array',
            'assignors.*.contact_id' => 'nullable|exists:contacts,id',
            'assignors.*.role' => 'nullable|in:parte,advogado',
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
        $assignorsData = $data['assignors'] ?? [];
        unset($data['assignors']);

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

        $writ->assignors()->delete();
        foreach ($assignorsData as $a) {
            if (!empty($a['contact_id'])) {
                WritAssignor::create([
                    'writ_id' => $writ->id,
                    'contact_id' => $a['contact_id'],
                    'role' => $a['role'] ?? 'parte',
                ]);
            }
        }

        session()->flash('status', 'Requisitório salvo.');
        return $this->redirectRoute('writs.show', $writ, navigate: true);
    }

    public function with(): array
    {
        return [
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'contacts' => Contact::active()->orderBy('name')->get(),
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
            <div class="flex items-center justify-between mb-xs">
                <h3 class="text-md font-semibold">Cedentes</h3>
                <button type="button" wire:click="addAssignor" class="fx-btn fx-btn--text fx-btn--sm">+ Adicionar cedente</button>
            </div>
            <div class="flex flex-col gap-xs">
                @foreach ($assignors as $i => $assignor)
                    <div class="flex gap-sm items-end border border-mono-100 rounded-md p-sm bg-mono-50" wire:key="assignor-{{ $i }}">
                        <div class="flex-1">
                            <label class="block text-xxs text-mono-600 mb-xxxs">Contato</label>
                            <select wire:model="assignors.{{ $i }}.contact_id" class="fx-form-field">
                                <option value="">— selecionar contato —</option>
                                @foreach ($contacts as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}{{ $c->document ? ' · '.$c->document : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-36">
                            <label class="block text-xxs text-mono-600 mb-xxxs">Papel</label>
                            <select wire:model="assignors.{{ $i }}.role" class="fx-form-field">
                                <option value="parte">Parte</option>
                                <option value="advogado">Advogado</option>
                            </select>
                        </div>
                        @if (count($assignors) > 1)
                            <button type="button" wire:click="removeAssignor({{ $i }})" class="text-system-error text-sm px-xs h-9 hover:opacity-70 shrink-0" title="Remover">✕</button>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($contacts->isEmpty())
                <p class="text-xxs text-mono-600 mt-xs">Nenhum contato ativo cadastrado. <a href="{{ route('contacts.create') }}" class="text-primary-500 hover:underline">Cadastrar contato →</a></p>
            @endif
        </section>

        <section>
            <h3 class="text-md font-semibold mb-xs">Valores e Deságio</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                <x-fx.input label="Valor de face" type="text" x-money wire:model.live="face_value" />
                <x-fx.input label="Valor pago ao cedente" type="text" x-money wire:model.live="paid_amount" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Deságio (calculado)</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="{{ number_format($this->discountPreview(), 2, ',', '.') }}%" />
                    </div>
                </div>
                <x-fx.input label="Recebimento estimado" type="text" x-money wire:model="estimated_receipt_amount" />
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

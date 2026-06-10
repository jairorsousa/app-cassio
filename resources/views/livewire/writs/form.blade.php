<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use App\Domains\Writs\Models\WritStageHistory;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?Writ $writ = null;

    public string $type = 'rpv';
    public string $stage = 'negotiation';
    public string $process_number = '';
    public string $court = '';
    public string $debtor_entity = '';
    public string $credit_nature = '';

    public array $assignors = [['contact_id' => '', 'role' => 'parte']];

    public string $face_value = '0';
    public string $negotiated_amount = '0';
    public string $proposed_amount = '0';
    public string $paid_amount = '0';
    public string $notary_expenses_amount = '0';
    public string $other_expenses_amount = '0';
    public string $estimated_receipt_amount = '0';
    public ?int $estimated_months = null;
    public string $cession_at = '';
    public string $paid_at = '';
    public string $finalized_at = '';
    public string $actual_receipt_amount = '0';

    public ?int $source_bank_account_id = null;
    public ?int $destination_bank_account_id = null;

    public string $notes = '';

    public function mount(?Writ $writ = null): void
    {
        if ($writ && $writ->exists) {
            $this->writ = $writ->load('assignors.contact');
            foreach (['type', 'stage', 'process_number', 'court', 'debtor_entity', 'credit_nature', 'notes'] as $f) {
                $this->{$f} = (string) ($writ->{$f} ?? '');
            }
            $this->face_value = (string) $writ->face_value;
            $this->negotiated_amount = (string) $writ->negotiated_amount;
            $this->proposed_amount = (string) $writ->proposed_amount;
            $this->paid_amount = (string) $writ->paid_amount;
            $this->notary_expenses_amount = (string) $writ->notary_expenses_amount;
            $this->other_expenses_amount = (string) $writ->other_expenses_amount;
            $this->estimated_receipt_amount = (string) $writ->estimated_receipt_amount;
            $this->estimated_months = $writ->estimated_months;
            $this->cession_at = $writ->cession_at?->format('Y-m-d\TH:i') ?? '';
            $this->paid_at = $writ->paid_at?->format('Y-m-d') ?? '';
            $this->finalized_at = $writ->finalized_at?->format('Y-m-d') ?? '';
            $this->actual_receipt_amount = (string) ($writ->actual_receipt_amount ?? '0');
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
            'stage' => 'required|in:'.implode(',', Writ::STAGES),
            'process_number' => 'nullable|string|max:80',
            'court' => 'nullable|string|max:120',
            'debtor_entity' => 'nullable|string|max:120',
            'credit_nature' => 'nullable|string|max:120',
            'assignors' => 'array',
            'assignors.*.contact_id' => 'nullable|exists:contacts,id',
            'assignors.*.role' => 'nullable|in:parte,advogado',
            'face_value' => 'required|numeric|min:0',
            'negotiated_amount' => 'required|numeric|min:0',
            'proposed_amount' => 'required|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'notary_expenses_amount' => 'required|numeric|min:0',
            'other_expenses_amount' => 'required|numeric|min:0',
            'estimated_receipt_amount' => 'required|numeric|min:0',
            'estimated_months' => 'nullable|integer|min:0',
            'cession_at' => 'nullable|date',
            'paid_at' => 'nullable|date',
            'finalized_at' => 'nullable|date',
            'actual_receipt_amount' => 'nullable|numeric|min:0',
            'source_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'destination_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function discountPreview(): float
    {
        $face = $this->discountBaseValue();
        $paid = $this->moneyValue($this->paid_amount);
        $proposed = $this->moneyValue($this->proposed_amount);
        
        $amount = ($this->usesPaymentFields() && $paid > 0) ? $paid : $proposed;

        if ($face <= 0) return 0;
        return round((1 - $amount / $face) * 100, 2);
    }

    public function totalCostPreview(): float
    {
        $paid = $this->moneyValue($this->paid_amount);
        $proposed = $this->moneyValue($this->proposed_amount);
        $notary = $this->moneyValue($this->notary_expenses_amount);
        $other = $this->moneyValue($this->other_expenses_amount);

        $amount = ($this->usesPaymentFields() && $paid > 0) ? $paid : $proposed;
        return round($amount + $notary + $other, 2);
    }

    public function estimatedProfitPreview(): float
    {
        $cost = $this->totalCostPreview();
        $receipt = $this->moneyValue($this->estimated_receipt_amount);
        return round($receipt - $cost, 2);
    }

    public function estimatedProfitPercentagePreview(): float
    {
        $cost = $this->totalCostPreview();
        if ($cost <= 0) return 0;
        
        $profit = $this->estimatedProfitPreview();
        return round(($profit / $cost) * 100, 2);
    }

    public function estimatedProfitPerMonthPreview(): float
    {
        $months = (int) $this->estimated_months;
        if ($months <= 0) return 0.0;
        return round($this->estimatedProfitPreview() / $months, 2);
    }

    public function save()
    {
        if ($this->writ) {
            $this->stage = $this->writ->stage;
        }

        $this->normalizeMoneyFields();

        $data = $this->validate();
        $data = $this->prepareDataForStage($data);
        $assignorsData = $data['assignors'] ?? [];
        unset($data['assignors']);

        $face = $this->discountBaseValue();
        $paid = (float) $data['paid_amount'];
        $proposed = (float) $data['proposed_amount'];
        $amount = ($this->usesPaymentFields() && $paid > 0) ? $paid : $proposed;
        $data['discount_percentage'] = $face > 0 ? round((1 - $amount / $face) * 100, 3) : 0;

        if ($this->writ) {
            $previousStage = $this->writ->stage;
            $this->writ->update($data);
            $writ = $this->writ->fresh();

            if ($previousStage !== $writ->stage) {
                $this->recordStageHistory($writ, $previousStage, $writ->stage);
                $this->dispatchStageEvents($writ);
            }
        } else {
            $writ = Writ::create($data);
            $this->recordStageHistory($writ, null, $writ->stage);
            $this->dispatchStageEvents($writ->fresh());
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

    private function moneyValue(string|int|float|null $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $value = (string) $value;

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    private function normalizeMoneyFields(): void
    {
        foreach ([
            'face_value',
            'negotiated_amount',
            'proposed_amount',
            'paid_amount',
            'notary_expenses_amount',
            'other_expenses_amount',
            'estimated_receipt_amount',
            'actual_receipt_amount',
        ] as $field) {
            $this->{$field} = (string) $this->moneyValue($this->{$field});
        }
    }

    public function usesCessionDate(): bool
    {
        return $this->stage === 'pending';
    }

    public function usesPaymentFields(): bool
    {
        return in_array($this->stage, ['paid', 'petitioning', 'finalized'], true);
    }

    public function usesReceiptFields(): bool
    {
        return $this->stage === 'finalized';
    }

    private function discountBaseValue(): float
    {
        $negotiated = $this->moneyValue($this->negotiated_amount);

        return $negotiated > 0 ? $negotiated : $this->moneyValue($this->face_value);
    }

    private function prepareDataForStage(array $data): array
    {
        foreach (['cession_at', 'paid_at', 'finalized_at'] as $field) {
            $data[$field] = blank($data[$field] ?? null) ? null : $data[$field];
        }

        foreach (['source_bank_account_id', 'destination_bank_account_id'] as $field) {
            $data[$field] = blank($data[$field] ?? null) ? null : $data[$field];
        }

        if (! $this->usesPaymentFields()) {
            $data['paid_amount'] = 0;
            $data['notary_expenses_amount'] = 0;
            $data['other_expenses_amount'] = 0;
            $data['paid_at'] = null;
            $data['source_bank_account_id'] = null;
            $data['destination_bank_account_id'] = null;
        } elseif ($data['paid_at'] === null) {
            $data['paid_at'] = now()->toDateString();
        }

        if (! $this->usesReceiptFields()) {
            $data['finalized_at'] = null;
            $data['actual_receipt_amount'] = null;
        } elseif ($data['finalized_at'] === null) {
            $data['finalized_at'] = now()->toDateString();
        }

        return $data;
    }

    private function recordStageHistory(Writ $writ, ?string $fromStage, string $toStage): void
    {
        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'transitioned_at' => now(),
            'user_id' => auth()->id(),
        ]);
    }

    private function dispatchStageEvents(Writ $writ): void
    {
        if (in_array($writ->stage, ['paid', 'petitioning', 'finalized'], true)) {
            WritMovedToPaid::dispatch($writ);
        }

        if ($writ->stage === 'finalized') {
            WritMovedToFinalized::dispatch($writ);
        }
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
            <div class="space-y-sm">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                    <div>
                        <label class="block text-xxs text-mono-600 mb-xxxs">Tipo</label>
                        <select wire:model="type" class="fx-form-field">
                            <option value="rpv">RPV</option>
                            <option value="precatorio">Precatório</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xxs text-mono-600 mb-xxxs">Etapa</label>
                        @if ($writ)
                            <div class="fx-form-field bg-mono-50">
                                <input type="text" disabled value="{{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$stage] ?? $stage }}" />
                            </div>
                        @else
                            <select wire:model.live="stage" class="fx-form-field">
                                @foreach (\App\Domains\Writs\Models\Writ::STAGES as $stageOption)
                                    <option value="{{ $stageOption }}">{{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$stageOption] }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <x-fx.input label="Número do processo" wire:model="process_number" x-process-number />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                    <x-fx.input label="Vara / Tribunal" wire:model="court" />
                    <x-fx.input label="Ente devedor" wire:model="debtor_entity" placeholder="União, INSS, Estado..." />
                    <x-fx.input label="Natureza do crédito" wire:model="credit_nature" placeholder="alimentar, comum..." />
                </div>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-sm">
                <x-fx.input label="Valor do requisitório" type="text" x-money wire:model.live="face_value" />
                <x-fx.input label="Valor da parte negociada" type="text" x-money wire:model.live="negotiated_amount" />
                <x-fx.input label="Valor da proposta" type="text" x-money wire:model.live="proposed_amount" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Deságio %</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="{{ number_format($this->discountPreview(), 2, ',', '.') }}%" class="font-semibold" />
                    </div>
                </div>
                
                <x-fx.input label="Prazo estimado (meses)" type="number" min="0" wire:model.live="estimated_months" />
                <x-fx.input label="Recebimento estimado" type="text" x-money wire:model.live="estimated_receipt_amount" />
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Lucro estimado (R$)</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="R$ {{ number_format($this->estimatedProfitPreview(), 2, ',', '.') }}" class="font-semibold text-up" />
                    </div>
                </div>
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Lucro estimado (%)</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="{{ number_format($this->estimatedProfitPercentagePreview(), 2, ',', '.') }}%" class="font-semibold text-up" />
                    </div>
                </div>
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Lucro / Mês</label>
                    <div class="fx-form-field bg-mono-50">
                        <input type="text" disabled value="R$ {{ number_format($this->estimatedProfitPerMonthPreview(), 2, ',', '.') }}" class="font-semibold text-up" />
                    </div>
                </div>

                @if ($this->usesPaymentFields())
                    <div class="md:col-span-4 grid grid-cols-1 md:grid-cols-3 gap-sm mt-xs pt-xs border-t border-mono-100">
                        <x-fx.input label="Valor pago ao cedente" type="text" x-money wire:model.live="paid_amount" />
                        <x-fx.input label="Despesas cartorais" type="text" x-money wire:model.live="notary_expenses_amount" />
                        <x-fx.input label="Outras despesas" type="text" x-money wire:model.live="other_expenses_amount" />
                    </div>
                @endif
            </div>
        </section>

        @if ($this->usesCessionDate() || $this->usesPaymentFields() || $this->usesReceiptFields())
            <section>
                <h3 class="text-md font-semibold mb-xs">Datas da etapa</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-sm">
                    @if ($this->usesCessionDate())
                        <x-fx.input label="Data da cessão" type="datetime-local" wire:model="cession_at" />
                    @endif

                    @if ($this->usesPaymentFields())
                        <x-fx.input label="Data do pagamento" type="date" wire:model="paid_at" />
                    @endif

                    @if ($this->usesReceiptFields())
                        <x-fx.input label="Data de recebimento" type="date" wire:model="finalized_at" />
                        <x-fx.input label="Valor recebido" type="text" x-money wire:model="actual_receipt_amount" />
                    @endif
                </div>
            </section>
        @endif

        @if ($this->usesPaymentFields())
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
                    <label class="block text-xxs text-mono-600 mb-xxxs">{{ $this->usesReceiptFields() ? 'Conta (recebimento)' : 'Conta de destino (recebimento)' }}</label>
                    <select wire:model="destination_bank_account_id" class="fx-form-field">
                        <option value="">— selecionar —</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>
        @endif

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

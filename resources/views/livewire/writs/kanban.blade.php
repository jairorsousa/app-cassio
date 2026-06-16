<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Jobs\SyncWritAwaitingReceiptToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritCessionToGoogleCalendar;
use App\Domains\Writs\Jobs\SyncWritPetitionToGoogleCalendar;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use App\Domains\Writs\Models\WritStageHistory;
use App\Domains\Writs\Services\WritService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Lazy] class extends Component {
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="space-y-6 animate-pulse">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="h-24 bg-mono-100 rounded-2xl"></div>
                <div class="h-24 bg-mono-100 rounded-2xl"></div>
                <div class="h-24 bg-mono-100 rounded-2xl"></div>
            </div>
            <div class="h-12 bg-mono-100 rounded-pill"></div>
            <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-7 gap-4">
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
                <div class="h-96 bg-mono-100 rounded-2xl"></div>
            </div>
        </div>
        HTML;
    }

    #[Url]
    public string $type = '';
    #[Url]
    public string $debtor = '';
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    public bool $showFormModal = false;
    public bool $showPetitionModal = false;
    public ?int $promptPetitionWritId = null;
    public string $promptPetitionAt = '';

    public bool $showAwaitingReceiptModal = false;
    public ?int $promptAwaitingReceiptWritId = null;
    public string $promptAwaitingReceiptAt = '';
    
    public bool $showFinalizedModal = false;
    public ?int $promptFinalizedWritId = null;
    public string $promptFinalizedAt = '';
    public string $promptActualReceiptAmount = '';
    public ?int $promptDestinationBankAccountId = null;

    public bool $showLostModal = false;
    public ?int $promptLostWritId = null;
    public string $promptLostReason = '';

    public bool $showFilters = false;
    public string $formType = 'rpv';
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
    public string $lost_reason = '';
    public ?int $source_bank_account_id = null;
    public ?int $destination_bank_account_id = null;
    public string $notes = '';

    public bool $showCessionModal = false;
    public ?int $cessionWritId = null;
    public string $promptCessionAt = '';

    public bool $showPaidModal = false;
    public ?int $promptPaidWritId = null;
    public string $promptPaidAmount = '';
    public string $promptNotaryExpenses = '';
    public string $promptOtherExpenses = '';
    public string $promptPaidAt = '';
    public ?int $promptSourceBankAccountId = null;
    public string $promptTransactionNote = '';

    public function rules(): array
    {
        return [
            'formType' => 'required|in:rpv,precatorio',
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
            'lost_reason' => 'nullable|string|max:2000',
            'source_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'destination_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'notes' => 'nullable|string',
        ];
    }

    public function create(?string $stage = null): void
    {
        $this->resetForm();
        if ($stage !== null && in_array($stage, Writ::STAGES, true)) {
            $this->stage = $stage;
        }
        $this->showFormModal = true;
    }

    public function cancelCreate(): void
    {
        $this->resetForm();
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

    public function discountPreview(): float
    {
        $face = $this->discountBaseValue();
        $amount = $this->totalCostPreview();

        if ($face <= 0) {
            return 0;
        }

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

    public function saveWrit(): void
    {
        $this->normalizeMoneyFields();

        $data = $this->validate();

        if ($this->stage === 'lost' && blank($this->lost_reason)) {
            $this->addError('lost_reason', 'Informe o motivo para marcar o requisitório como perdido.');

            return;
        }

        $data = $this->prepareDataForStage($data);
        $assignorsData = $data['assignors'] ?? [];
        unset($data['assignors']);

        $data['type'] = $data['formType'];
        unset($data['formType']);

        $face = $this->discountBaseValue();
        $paid = (float) $data['paid_amount'];
        $proposed = (float) $data['proposed_amount'];
        $amount = ($this->usesPaymentFields() && $paid > 0) ? $paid : $proposed;
        $data['discount_percentage'] = $face > 0 ? round((1 - $amount / $face) * 100, 3) : 0;

        $writ = Writ::create($data);

        WritStageHistory::create([
            'writ_id' => $writ->id,
            'from_stage' => null,
            'to_stage' => $writ->stage,
            'transitioned_at' => now(),
            'user_id' => auth()->id(),
        ]);

        $this->dispatchStageEvents($writ->fresh());

        foreach ($assignorsData as $assignor) {
            if (!empty($assignor['contact_id'])) {
                WritAssignor::create([
                    'writ_id' => $writ->id,
                    'contact_id' => $assignor['contact_id'],
                    'role' => $assignor['role'] ?? 'parte',
                ]);
            }
        }

        $this->resetForm();
        session()->flash('status', 'Requisitório criado.');
    }

    private function moneyValue(string|int|float|null $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $value = (string) $value;

        if (str_contains($value, ',')) {
            $digits = preg_replace('/\D/', '', $value);
            return (float) ($digits / 100);
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
        return in_array($this->stage, ['paid', 'petitioning', 'awaiting_receipt', 'finalized'], true);
    }

    public function usesReceiptFields(): bool
    {
        return $this->stage === 'finalized';
    }

    public function usesLostReason(): bool
    {
        return $this->stage === 'lost';
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

        if (! $this->usesLostReason()) {
            $data['lost_reason'] = null;
            $data['lost_at'] = null;
        } else {
            $data['lost_reason'] = trim((string) $data['lost_reason']);
            $data['lost_at'] = now();
        }

        return $data;
    }

    private function dispatchStageEvents(Writ $writ): void
    {
        if (in_array($writ->stage, ['paid', 'petitioning', 'awaiting_receipt', 'finalized'], true)) {
            WritMovedToPaid::dispatch($writ);
        }

        if ($writ->stage === 'finalized') {
            WritMovedToFinalized::dispatch($writ);
        }

        if ($writ->stage === 'pending' && $writ->cession_at) {
            SyncWritCessionToGoogleCalendar::dispatch($writ->id)->afterCommit();
        }

        if ($writ->stage === 'petitioning' && $writ->petitioned_at) {
            SyncWritPetitionToGoogleCalendar::dispatch($writ->id)->afterCommit();
        }

        if ($writ->stage === 'awaiting_receipt' && $writ->awaiting_receipt_at) {
            SyncWritAwaitingReceiptToGoogleCalendar::dispatch($writ->id)->afterCommit();
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'showFormModal',
            'formType',
            'stage',
            'process_number',
            'court',
            'debtor_entity',
            'credit_nature',
            'assignors',
            'face_value',
            'negotiated_amount',
            'proposed_amount',
            'paid_amount',
            'notary_expenses_amount',
            'other_expenses_amount',
            'estimated_receipt_amount',
            'estimated_months',
            'cession_at',
            'paid_at',
            'finalized_at',
            'actual_receipt_amount',
            'lost_reason',
            'source_bank_account_id',
            'destination_bank_account_id',
            'notes',
        ]);

        $this->formType = 'rpv';
        $this->stage = 'negotiation';
        $this->assignors = [['contact_id' => '', 'role' => 'parte']];
        $this->face_value = '0';
        $this->negotiated_amount = '0';
        $this->proposed_amount = '0';
        $this->paid_amount = '0';
        $this->notary_expenses_amount = '0';
        $this->other_expenses_amount = '0';
        $this->estimated_receipt_amount = '0';
        $this->actual_receipt_amount = '0';
        $this->lost_reason = '';
    }

    public function delete(int $id): void
    {
        $writ = Writ::findOrFail($id);
        $writ->transactions()->delete();
        $writ->history()->delete();
        \Spatie\Activitylog\Models\Activity::where('subject_type', Writ::class)
            ->where('subject_id', $writ->id)
            ->delete();
        $writ->forceDelete();
        session()->flash('status', 'Requisitório excluído.');
    }

    public function moveCard(int $writId, string $newStage, WritService $service): void
    {
        try {
            $writ = Writ::findOrFail($writId);
            $service->transitionTo($writ, $newStage);
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS[$newStage].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function promptCessionDate(int $id): void
    {
        $this->cessionWritId = $id;
        $this->promptCessionAt = now()->format('Y-m-d\TH:i');
        $this->showCessionModal = true;
    }

    public function confirmCessionDate(WritService $service): void
    {
        $this->validate(['promptCessionAt' => 'required|date']);

        try {
            $writ = Writ::findOrFail($this->cessionWritId);
            $writ->update(['cession_at' => $this->promptCessionAt]);
            $service->transitionTo($writ, 'pending');
            $this->showCessionModal = false;
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['pending'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelCessionDate(): void
    {
        $this->showCessionModal = false;
        $this->cessionWritId = null;
    }

    public function promptPaidDate(int $id): void
    {
        $this->promptPaidWritId = $id;
        $writ = Writ::findOrFail($id);
        
        $this->promptPaidAmount = (string) $writ->paid_amount;
        $this->promptNotaryExpenses = (string) $writ->notary_expenses_amount;
        $this->promptOtherExpenses = (string) $writ->other_expenses_amount;
        $this->promptPaidAt = now()->format('Y-m-d');
        $this->promptSourceBankAccountId = $writ->source_bank_account_id;
        $this->promptTransactionNote = '';
        
        $this->showPaidModal = true;
    }

    public function confirmPaidDate(WritService $service): void
    {
        $this->validate([
            'promptPaidAmount' => 'required',
            'promptNotaryExpenses' => 'required',
            'promptOtherExpenses' => 'required',
            'promptPaidAt' => 'required|date',
            'promptSourceBankAccountId' => 'required|exists:bank_accounts,id',
        ]);

        try {
            $writ = Writ::findOrFail($this->promptPaidWritId);
            $writ->update([
                'paid_amount' => $this->moneyValue($this->promptPaidAmount),
                'notary_expenses_amount' => $this->moneyValue($this->promptNotaryExpenses),
                'other_expenses_amount' => $this->moneyValue($this->promptOtherExpenses),
            ]);
            
            $service->transitionTo($writ, 'paid', [
                'paid_at' => $this->promptPaidAt,
                'source_bank_account_id' => $this->promptSourceBankAccountId,
                'notes' => $this->promptTransactionNote ?: null,
            ]);
            $this->showPaidModal = false;
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['paid'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelPaidDate(): void
    {
        $this->showPaidModal = false;
        $this->promptPaidWritId = null;
    }

    public function promptPetitionDate(int $id): void
    {
        $this->promptPetitionWritId = $id;
        $this->promptPetitionAt = now()->format('Y-m-d\TH:i');
        $this->showPetitionModal = true;
    }

    public function confirmPetitionDate(WritService $service): void
    {
        $this->validate(['promptPetitionAt' => 'required|date']);

        try {
            $writ = Writ::findOrFail($this->promptPetitionWritId);
            $service->transitionTo($writ, 'petitioning', [
                'petitioned_at' => $this->promptPetitionAt,
            ]);
            $this->showPetitionModal = false;
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['petitioning'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelPetitionDate(): void
    {
        $this->showPetitionModal = false;
        $this->promptPetitionWritId = null;
    }

    public function promptAwaitingReceiptDate(int $id): void
    {
        $this->promptAwaitingReceiptWritId = $id;
        $this->promptAwaitingReceiptAt = now()->format('Y-m-d\TH:i');
        $this->showAwaitingReceiptModal = true;
    }

    public function confirmAwaitingReceiptDate(WritService $service): void
    {
        $this->validate(['promptAwaitingReceiptAt' => 'required|date']);

        try {
            $writ = Writ::findOrFail($this->promptAwaitingReceiptWritId);
            $service->transitionTo($writ, 'awaiting_receipt', [
                'awaiting_receipt_at' => $this->promptAwaitingReceiptAt,
            ]);
            $this->showAwaitingReceiptModal = false;
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['awaiting_receipt'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelAwaitingReceiptDate(): void
    {
        $this->showAwaitingReceiptModal = false;
        $this->promptAwaitingReceiptWritId = null;
    }

    public function promptFinalizedDate(int $id): void
    {
        $this->promptFinalizedWritId = $id;
        $writ = Writ::findOrFail($id);
        
        $this->promptFinalizedAt = now()->format('Y-m-d');
        $this->promptActualReceiptAmount = (string) $writ->actual_receipt_amount;
        $this->promptDestinationBankAccountId = $writ->destination_bank_account_id;
        $this->promptTransactionNote = '';
        
        $this->showFinalizedModal = true;
    }

    public function confirmFinalizedDate(WritService $service): void
    {
        $this->validate([
            'promptFinalizedAt' => 'required|date',
            'promptActualReceiptAmount' => 'required',
            'promptDestinationBankAccountId' => 'required|exists:bank_accounts,id',
        ]);

        try {
            $writ = Writ::findOrFail($this->promptFinalizedWritId);
            $service->transitionTo($writ, 'finalized', [
                'finalized_at' => $this->promptFinalizedAt,
                'actual_receipt_amount' => $this->moneyValue($this->promptActualReceiptAmount),
                'destination_bank_account_id' => $this->promptDestinationBankAccountId,
                'notes' => $this->promptTransactionNote ?: null,
            ]);
            $this->showFinalizedModal = false;
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['finalized'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelFinalizedDate(): void
    {
        $this->showFinalizedModal = false;
        $this->promptFinalizedWritId = null;
    }

    public function promptLostReason(int $id): void
    {
        $this->promptLostWritId = $id;
        $this->promptLostReason = '';
        $this->showLostModal = true;
    }

    public function confirmLostReason(WritService $service): void
    {
        $this->validate([
            'promptLostReason' => 'required|string|max:2000',
        ], [
            'promptLostReason.required' => 'Informe o motivo para marcar o requisitório como perdido.',
        ]);

        try {
            $writ = Writ::findOrFail($this->promptLostWritId);
            $service->transitionTo($writ, 'lost', [
                'lost_reason' => $this->promptLostReason,
            ]);
            $this->showLostModal = false;
            $this->promptLostWritId = null;
            $this->promptLostReason = '';
            session()->flash('status', 'Card movido para '.Writ::STAGE_LABELS['lost'].'.');
        } catch (\DomainException|\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelLostReason(): void
    {
        $this->showLostModal = false;
        $this->promptLostWritId = null;
        $this->promptLostReason = '';
    }

    public function clearFilters(): void
    {
        $this->reset(['type', 'debtor', 'from', 'to']);
    }

    public function with(): array
    {
        $q = Writ::with('assignors.contact');
        if ($this->type) $q->where('type', $this->type);
        if ($this->debtor) {
            $q->where(function ($query) {
                $query->where('debtor_entity', 'like', '%'.$this->debtor.'%')
                    ->orWhere('assignor_name', 'like', '%'.$this->debtor.'%')
                    ->orWhere('process_number', 'like', '%'.$this->debtor.'%');
            });
        }
        if ($this->from) $q->where('paid_at', '>=', $this->from);
        if ($this->to) $q->where('paid_at', '<=', $this->to);

        $writs = $q->orderByDesc('id')->get()->groupBy('stage');

        $stages = [];
        foreach (Writ::STAGES as $stage) {
            $cards = $writs->get($stage, collect());
            $stages[] = [
                'key' => $stage,
                'label' => Writ::STAGE_LABELS[$stage],
                'count' => $cards->count(),
                'face_total' => $cards->sum('face_value'),
                'cards' => $cards,
            ];
        }

        $totalFace = Writ::sum('face_value');
        $totalOpenInvested = Writ::where('stage', '!=', 'finalized')->sum('paid_amount');
        $finalizedWrits = Writ::where('stage', 'finalized')->get();
        $totalReceived = $finalizedWrits->sum('actual_receipt_amount');
        $totalFinalizedCost = $finalizedWrits->sum(fn (Writ $writ) => $writ->totalCost());
        $profitAmount = round((float) $totalReceived - (float) $totalFinalizedCost, 2);
        $profitPercentage = $totalFinalizedCost > 0
            ? round($profitAmount / (float) $totalFinalizedCost * 100, 2)
            : 0.0;

        return compact('stages', 'totalFace', 'totalOpenInvested', 'totalReceived', 'profitAmount', 'profitPercentage') + [
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'contacts' => Contact::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Requisitórios · Pipeline</x-slot>

<div class="flex flex-col gap-6" x-data="writsKanban(@js(\App\Domains\Writs\Models\Writ::STAGES))">
    @if (session('status'))
        <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert>
    @endif
    @if (session('error'))
        <x-jr.alert variant="error">{{ session('error') }}</x-jr.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <x-jr.card>
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-info-bg text-info">
                    <span class="material-icons-outlined text-[22px]">request_quote</span>
                </div>
                <div>
                    <p class="text-xs font-medium text-mono-600">Total requisitórios</p>
                    <p class="mt-1 text-2xl font-bold text-mono-900">R$ {{ number_format($totalFace, 2, ',', '.') }}</p>
                </div>
            </div>
        </x-jr.card>

        <x-jr.card>
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[22px]">payments</span>
                </div>
                <div>
                    <p class="text-xs font-medium text-mono-600">Total Investido em aberto</p>
                    <p class="mt-1 text-2xl font-bold text-mono-900">R$ {{ number_format($totalOpenInvested, 2, ',', '.') }}</p>
                </div>
            </div>
        </x-jr.card>

        <x-jr.card>
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-up-bg text-up">
                    <span class="material-icons-outlined text-[22px]">account_balance_wallet</span>
                </div>
                <div>
                    <p class="text-xs font-medium text-mono-600">Total recebido (finalizados)</p>
                    <p class="mt-1 text-2xl font-bold text-up">R$ {{ number_format($totalReceived, 2, ',', '.') }}</p>
                </div>
            </div>
        </x-jr.card>

        <x-jr.card>
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $profitAmount >= 0 ? 'bg-up-bg text-up' : 'bg-down-bg text-down' }}">
                    <span class="material-icons-outlined text-[22px]">trending_up</span>
                </div>
                <div>
                    <p class="text-xs font-medium text-mono-600">Lucro líquido</p>
                    <p class="mt-1 text-2xl font-bold {{ $profitAmount >= 0 ? 'text-up' : 'text-down' }}">R$ {{ number_format($profitAmount, 2, ',', '.') }}</p>
                    <p class="mt-1 text-xs font-semibold {{ $profitAmount >= 0 ? 'text-up' : 'text-down' }}">{{ number_format($profitPercentage, 2, ',', '.') }}%</p>
                </div>
            </div>
        </x-jr.card>
    </div>

    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
        <div class="min-w-0 flex-1">
            <div class="flex h-12 items-center gap-3 rounded-pill border border-mono-200 bg-mono-white px-4 shadow-sm transition-all focus-within:border-primary-500 focus-within:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                <span class="material-icons-outlined text-[20px] text-mono-300">search</span>
                <input
                    type="text"
                    wire:model.live.debounce.500ms="debtor"
                    placeholder="Buscar requisitório, cedente ou ente devedor..."
                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-mono-900 placeholder:text-mono-300 focus:outline-none focus:ring-0"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="type" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 pr-10 text-sm text-mono-900 transition-colors focus:border-primary-500 focus:ring-0">
                <option value="">Todos os tipos</option>
                <option value="rpv">RPV</option>
                <option value="precatorio">Precatório</option>
            </select>

            <input type="date" wire:model.live="from" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-colors focus:border-primary-500 focus:ring-0" />
            <input type="date" wire:model.live="to" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-colors focus:border-primary-500 focus:ring-0" />

            @if ($type || $debtor || $from || $to)
                <button type="button" class="h-12 rounded-pill px-4 text-sm font-semibold text-mono-600 transition-colors hover:bg-mono-100 hover:text-mono-900" wire:click="clearFilters">
                    Limpar
                </button>
            @endif

            <x-jr.button type="button" wire:click="create">
                <span class="material-icons-outlined text-[18px]">add</span>
                Novo Requisitório
            </x-jr.button>
        </div>
    </div>

    @php
        $hasAnyWrit = collect($stages)->sum('count') > 0;
        $stageMeta = [
            'negotiation' => ['icon' => 'person_add', 'dot' => 'bg-info', 'tint' => 'bg-info-bg text-info', 'bar' => 'bg-primary-500'],
            'pending' => ['icon' => 'edit_document', 'dot' => 'bg-primary-500', 'tint' => 'bg-primary-100 text-primary-500', 'bar' => 'bg-mono-400'],
            'paid' => ['icon' => 'payments', 'dot' => 'bg-up', 'tint' => 'bg-up-bg text-up', 'bar' => 'bg-success'],
            'petitioning' => ['icon' => 'gavel', 'dot' => 'bg-down', 'tint' => 'bg-down-bg text-down', 'bar' => 'bg-info'],
            'awaiting_receipt' => ['icon' => 'hourglass_top', 'dot' => 'bg-primary-500', 'tint' => 'bg-primary-100 text-primary-500', 'bar' => 'bg-primary-500'],
            'finalized' => ['icon' => 'emoji_events', 'dot' => 'bg-up', 'tint' => 'bg-up-bg text-up', 'bar' => 'border-t-[3px] border-dashed border-mono-300'],
            'lost' => ['icon' => 'block', 'dot' => 'bg-down', 'tint' => 'bg-down-bg text-down', 'bar' => 'bg-down'],
        ];
    @endphp

    @if (! $hasAnyWrit)
        <x-jr.empty-state
            icon="gavel"
            title="Nenhum requisitório no pipeline"
            description="Crie um card e arraste pelas etapas: Negociação, Cessão Pendente, Pago, Peticionar, Aguardando Recebimento, Finalizar e Perdido."
        >
            <x-jr.button type="button" wire:click="create" size="sm">Criar primeiro requisitório</x-jr.button>
        </x-jr.empty-state>
    @endif

    <div class="overflow-x-auto pb-2">
        <div class="grid min-w-[2100px] grid-cols-7 gap-4">
            @foreach ($stages as $stage)
                @php $meta = $stageMeta[$stage['key']] ?? $stageMeta['negotiation']; @endphp
                <section class="flex min-h-[520px] flex-col rounded-2xl border border-mono-100 bg-mono-50" data-stage="{{ $stage['key'] }}">
                    <div class="h-1 w-full shrink-0 rounded-t-2xl {{ $meta['bar'] }}"></div>
                    <div class="px-3 py-4">
                        <div class="writ-stage-header mb-4">
                            <div class="writ-stage-title">
                                <span class="material-icons-outlined text-[18px] text-mono-400">{{ $meta['icon'] }}</span>
                                <h3 class="text-sm font-bold text-mono-900" title="{{ $stage['label'] }}">{{ $stage['label'] }}</h3>
                            </div>

                            <div class="writ-stage-actions">
                                <span class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-mono-100 px-1.5 text-[11px] font-bold text-mono-600">{{ $stage['count'] }}</span>
                                <button type="button" wire:click="create('{{ $stage['key'] }}')" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-primary-500" title="Novo requisitório">
                                    <span class="material-icons-outlined text-[20px]">add</span>
                                </button>
                            </div>
                        </div>

                        <p class="mb-3 text-xs text-mono-600">R$ {{ number_format($stage['face_total'], 2, ',', '.') }}</p>

                        <div class="kanban-list flex flex-1 flex-col gap-3" data-stage="{{ $stage['key'] }}">
                            @foreach ($stage['cards'] as $w)
                                <article
                                    class="kanban-card cursor-grab rounded-2xl border border-mono-100 bg-mono-white p-4 shadow-card transition-all hover:-translate-y-0.5 hover:shadow-elevated"
                                    data-id="{{ $w->id }}"
                                    wire:key="writ-{{ $w->id }}"
                                >
                                    <div class="mb-3 flex items-start justify-between gap-3">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $meta['dot'] }}"></span>
                                            <a href="{{ route('writs.show', $w) }}" class="truncate text-sm font-bold text-mono-900 transition-colors hover:text-primary-500" title="{{ $w->process_number }}">
                                                {{ $w->process_number ?: 'Requisitório #'.$w->id }}
                                            </a>
                                        </div>

                                        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
                                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" @click="open = !open">
                                                <span class="material-icons-outlined text-[20px]">more_vert</span>
                                            </button>
                                            <div x-show="open" x-transition class="absolute right-0 top-9 z-dropdown w-40 rounded-xl border border-mono-100 bg-mono-white py-2 shadow-dropdown" style="display: none;">
                                                <a href="{{ route('writs.show', $w) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-mono-900 hover:bg-mono-50">
                                                    <span class="material-icons-outlined text-[18px] text-mono-400">visibility</span>
                                                    Abrir
                                                </a>
                                                <a href="{{ route('writs.edit', $w) }}" class="flex items-center gap-2 px-3 py-2 text-sm text-mono-900 hover:bg-mono-50">
                                                    <span class="material-icons-outlined text-[18px] text-mono-400">edit</span>
                                                    Editar
                                                </a>
                                                @if ($stage['key'] === 'negotiation')
                                                    <button
                                                        type="button"
                                                        wire:click="promptLostReason({{ $w->id }})"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-down hover:bg-down-bg"
                                                    >
                                                        <span class="material-icons-outlined text-[18px]">block</span>
                                                        Marcar como perdido
                                                    </button>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="delete({{ $w->id }})"
                                                    wire:confirm="Excluir este requisitório e todas as transações vinculadas?"
                                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-error hover:bg-down-bg"
                                                >
                                                    <span class="material-icons-outlined text-[18px]">delete_outline</span>
                                                    Excluir
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3 flex items-center gap-2 text-xs text-mono-600">
                                        <span class="material-icons-outlined text-[16px]">person</span>
                                        <span class="truncate">{{ $w->assignor_name ?: ($w->assignors->first()?->contact?->name ?: 'Cedente não informado') }}</span>
                                    </div>

                                    @if ((float) $w->negotiated_amount > 0)
                                        <div class="text-lg font-bold text-mono-900">R$ {{ number_format($w->negotiated_amount, 2, ',', '.') }}</div>
                                        <div class="mt-1 text-xs font-medium text-mono-600">Face: R$ {{ number_format($w->face_value, 2, ',', '.') }}</div>
                                    @else
                                        <div class="text-lg font-bold text-mono-900">R$ {{ number_format($w->face_value, 2, ',', '.') }}</div>
                                    @endif

                                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-mono-100 pt-2">
                                        <span class="truncate rounded-pill bg-mono-100 px-2.5 py-1 text-[10px] font-semibold text-mono-600">
                                            {{ $w->type === 'rpv' ? 'RPV' : 'Precatório' }}
                                        </span>
                                        <span class="text-xs font-medium text-mono-600">Custo: R$ {{ number_format($w->totalCost(), 2, ',', '.') }}</span>
                                    </div>

                                    @if ($stage['key'] === 'pending' && $w->cession_at)
                                    <div class="mt-3 flex items-center gap-2 text-xs text-mono-600">
                                        <span class="material-icons-outlined text-[16px]">edit_calendar</span>
                                        <span>Cessão: {{ $w->cession_at->format('d/m/Y \à\s H:i') }}</span>
                                    </div>
                                    @endif

                                    @if ($w->paid_at || !in_array($stage['key'], ['negotiation', 'pending']))
                                    <div class="mt-3 flex items-center gap-2 text-xs {{ $w->paid_at ? 'text-mono-600' : 'text-down' }}">
                                        <span class="material-icons-outlined text-[16px]">calendar_today</span>
                                        <span>{{ $w->paid_at ? $w->paid_at->format('d/m/Y') : 'Sem data de pagamento' }}</span>
                                    </div>
                                    @endif

                                    @if ($stage['key'] === 'awaiting_receipt' && $w->awaiting_receipt_at)
                                        <div class="mt-3 flex items-center gap-2 text-xs text-mono-600">
                                            <span class="material-icons-outlined text-[16px]">hourglass_top</span>
                                            <span>Aguardando: {{ $w->awaiting_receipt_at->format('d/m/Y \à\s H:i') }}</span>
                                        </div>
                                    @endif

                                    @if ($stage['key'] === 'finalized' && $w->actual_receipt_amount)
                                        <div class="mt-2 flex items-center justify-between rounded-xl bg-up-bg px-3 py-2 text-xs font-semibold text-up">
                                            <span>Recebido</span>
                                            <span>R$ {{ number_format($w->actual_receipt_amount, 2, ',', '.') }}</span>
                                        </div>
                                    @endif

                                    @if ($stage['key'] === 'lost' && $w->lost_reason)
                                        <div class="mt-3 rounded-xl bg-down-bg px-3 py-2 text-xs text-down">
                                            <div class="mb-1 flex items-center gap-1 font-semibold">
                                                <span class="material-icons-outlined text-[15px]">block</span>
                                                Motivo
                                            </div>
                                            <p class="line-clamp-3">{{ $w->lost_reason }}</p>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelCreate" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">Novo Requisitório</h3>
                    </div>

                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancelCreate" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="saveWrit" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">description</span>
                                    <h4 class="text-base font-bold text-mono-900">Identificação</h4>
                                </div>

                                <div class="space-y-4">
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Tipo</label>
                                            <div class="grid grid-cols-2 gap-3">
                                                <button
                                                    type="button"
                                                    wire:click="$set('formType', 'rpv')"
                                                    class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $formType === 'rpv' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                                >
                                                    RPV
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="$set('formType', 'precatorio')"
                                                    class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $formType === 'precatorio' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                                >
                                                    Precatório
                                                </button>
                                            </div>
                                            @error('formType') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Etapa</label>
                                            <select wire:model.live="stage" class="h-11 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                @foreach (\App\Domains\Writs\Models\Writ::STAGES as $stageOption)
                                                    <option value="{{ $stageOption }}">{{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$stageOption] }}</option>
                                                @endforeach
                                            </select>
                                            @error('stage') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>

                                        <x-jr.input label="Número do processo" icon="tag" wire:model="process_number" x-process-number />
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <x-jr.input label="Vara / Tribunal" icon="account_balance" wire:model="court" />
                                        <x-jr.input label="Ente devedor" icon="business" wire:model="debtor_entity" placeholder="União, INSS, Estado..." />
                                        <x-jr.input label="Natureza do crédito" icon="category" wire:model="credit_nature" placeholder="alimentar, comum..." />
                                    </div>
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center justify-between gap-3 border-b border-mono-100 pb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="material-icons-outlined text-[20px] text-primary-500">group</span>
                                        <h4 class="text-base font-bold text-mono-900">Cedentes</h4>
                                    </div>
                                    <button type="button" wire:click="addAssignor" class="inline-flex h-9 items-center gap-2 rounded-pill px-4 text-sm font-semibold text-primary-500 transition-colors hover:bg-primary-100">
                                        <span class="material-icons-outlined text-[18px]">add</span>
                                        Adicionar cedente
                                    </button>
                                </div>

                                <div class="space-y-3">
                                    @foreach ($assignors as $i => $assignor)
                                        <div class="grid grid-cols-1 items-end gap-3 rounded-2xl border border-mono-100 bg-mono-50 p-4 md:grid-cols-[1fr_160px_auto]" wire:key="modal-assignor-{{ $i }}">
                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-mono-600">Contato</label>
                                                <select wire:model="assignors.{{ $i }}.contact_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                    <option value="">Selecionar contato</option>
                                                    @foreach ($contacts as $contact)
                                                        <option value="{{ $contact->id }}">{{ $contact->name }}{{ $contact->document ? ' · '.$contact->document : '' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div>
                                                <label class="mb-2 block text-sm font-medium text-mono-600">Papel</label>
                                                <select wire:model="assignors.{{ $i }}.role" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                    <option value="parte">Parte</option>
                                                    <option value="advogado">Advogado</option>
                                                </select>
                                            </div>

                                            @if (count($assignors) > 1)
                                                <button type="button" wire:click="removeAssignor({{ $i }})" class="flex h-11 w-11 items-center justify-center rounded-xl text-error transition-colors hover:bg-down-bg" title="Remover cedente">
                                                    <span class="material-icons-outlined text-[20px]">delete_outline</span>
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if ($contacts->isEmpty())
                                    <p class="mt-3 text-xs text-mono-600">Nenhum contato ativo cadastrado. <a href="{{ route('contacts.create') }}" class="font-semibold text-primary-500 hover:underline">Cadastrar contato</a></p>
                                @endif
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">payments</span>
                                    <h4 class="text-base font-bold text-mono-900">Valores e Deságio</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                                    <x-jr.input label="Valor do requisitório" icon="attach_money" type="text" x-money wire:model.live="face_value" />
                                    <x-jr.input label="Valor da parte negociada" icon="price_check" type="text" x-money wire:model.live="negotiated_amount" />
                                    <x-jr.input label="Valor da proposta" icon="local_offer" type="text" x-money wire:model.live="proposed_amount" />
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Deságio %</label>
                                        <div class="flex h-12 items-center rounded-pill border border-mono-200 bg-mono-50 px-4 text-sm font-bold text-mono-900">
                                            {{ number_format($this->discountPreview(), 2, ',', '.') }}%
                                        </div>
                                    </div>
                                    
                                    <x-jr.input label="Prazo estimado (meses)" icon="calendar_month" type="number" min="0" wire:model.live="estimated_months" />
                                    <x-jr.input label="Recebimento estimado" icon="savings" type="text" x-money wire:model.live="estimated_receipt_amount" />
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Lucro estimado (R$)</label>
                                        <div class="flex h-12 items-center rounded-pill border border-mono-200 bg-mono-50 px-4 text-sm font-bold text-up">
                                            R$ {{ number_format($this->estimatedProfitPreview(), 2, ',', '.') }}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Lucro estimado (%)</label>
                                        <div class="flex h-12 items-center rounded-pill border border-mono-200 bg-mono-50 px-4 text-sm font-bold text-up">
                                            {{ number_format($this->estimatedProfitPercentagePreview(), 2, ',', '.') }}%
                                        </div>
                                    </div>

                                    @if ($this->usesPaymentFields())
                                        <div class="md:col-span-4 grid grid-cols-1 gap-4 md:grid-cols-3 mt-2 pt-4 border-t border-mono-100">
                                            <x-jr.input label="Valor pago ao cedente" icon="payments" type="text" x-money wire:model.live="paid_amount" />
                                            <x-jr.input label="Despesas cartorais" icon="receipt_long" type="text" x-money wire:model.live="notary_expenses_amount" />
                                            <x-jr.input label="Outras despesas" icon="request_quote" type="text" x-money wire:model.live="other_expenses_amount" />
                                        </div>
                                    @endif
                                </div>
                            </section>

                            @if ($this->usesCessionDate() || $this->usesPaymentFields() || $this->usesReceiptFields())
                                <section>
                                    <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                        <span class="material-icons-outlined text-[20px] text-primary-500">event</span>
                                        <h4 class="text-base font-bold text-mono-900">Datas da etapa</h4>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        @if ($this->usesCessionDate())
                                            <x-jr.input label="Data da cessão" icon="edit_calendar" type="datetime-local" wire:model="cession_at" />
                                        @endif

                                        @if ($this->usesPaymentFields())
                                            <x-jr.input label="Data do pagamento" icon="event_available" type="date" wire:model="paid_at" />
                                        @endif

                                        @if ($this->usesReceiptFields())
                                            <x-jr.input label="Data de recebimento" icon="event_available" type="date" wire:model="finalized_at" />
                                            <x-jr.input label="Valor recebido" icon="savings" type="text" x-money wire:model="actual_receipt_amount" />
                                        @endif
                                    </div>
                                </section>
                            @endif

                            @if ($this->usesLostReason())
                                <section>
                                    <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                        <span class="material-icons-outlined text-[20px] text-down">block</span>
                                        <h4 class="text-base font-bold text-mono-900">Motivo da perda</h4>
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Motivo *</label>
                                        <textarea wire:model="lost_reason" rows="4" required class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 focus:border-primary-500 focus:ring-0"></textarea>
                                        @error('lost_reason') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </section>
                            @endif

                            @if ($this->usesPaymentFields())
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance_wallet</span>
                                    <h4 class="text-base font-bold text-mono-900">Movimentação financeira</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta de origem</label>
                                        <select wire:model="source_bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Selecionar</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">{{ $this->usesReceiptFields() ? 'Conta (recebimento)' : 'Conta de destino' }}</label>
                                        <select wire:model="destination_bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Selecionar</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </section>
                            @endif

                            <section>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
                                <textarea wire:model="notes" rows="3" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 focus:border-primary-500 focus:ring-0"></textarea>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancelCreate">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            Criar Requisitório
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showCessionModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center bg-black/45 px-4">
            <div class="w-full max-w-md rounded-2xl bg-mono-white p-6 shadow-elevated" @click.stop>
                <h3 class="mb-4 text-lg font-bold text-mono-900">Data e hora da cessão</h3>
                <p class="mb-4 text-sm text-mono-600">Por favor, informe a data e hora em que a cessão foi realizada para continuar.</p>
                <form wire:submit="confirmCessionDate">
                    <x-jr.input label="Data da cessão" icon="edit_calendar" type="datetime-local" wire:model="promptCessionAt" required />
                    
                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button type="button" class="rounded-pill bg-mono-100 px-4 py-2 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancelCessionDate">Cancelar</button>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-pill bg-primary-500 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($showPaidModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center bg-black/45 px-4 overflow-y-auto">
            <div class="w-full max-w-lg rounded-2xl bg-mono-white p-6 shadow-elevated my-8" @click.stop>
                <h3 class="mb-4 text-lg font-bold text-mono-900">Confirmar Pagamento</h3>
                <p class="mb-4 text-sm text-mono-600">Por favor, preencha os dados de pagamento para mover o card para a etapa Pago.</p>
                <form wire:submit="confirmPaidDate">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-jr.input label="Valor pago ao cedente *" icon="payments" type="text" x-money wire:model="promptPaidAmount" required />
                            <x-jr.input label="Data do pagamento *" icon="event_available" type="date" wire:model="promptPaidAt" required />
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-jr.input label="Despesas cartorais *" icon="receipt_long" type="text" x-money wire:model="promptNotaryExpenses" required />
                            <x-jr.input label="Outras despesas *" icon="request_quote" type="text" x-money wire:model="promptOtherExpenses" required />
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Conta de origem *</label>
                            <select wire:model="promptSourceBankAccountId" required class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                <option value="">Selecionar</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Nota da transação (opcional)</label>
                            <textarea wire:model="promptTransactionNote" rows="2" class="w-full rounded-xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 focus:border-primary-500 focus:ring-0"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button type="button" class="rounded-pill bg-mono-100 px-4 py-2 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancelPaidDate">Cancelar</button>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-pill bg-primary-500 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Modal Confirmar Peticionamento -->
    <div
        x-show="$wire.showPetitionModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mono-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none;"
    >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden" @click.outside="$wire.cancelPetitionDate()">
            <div class="px-6 py-4 border-b border-mono-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-mono-900">Confirmar Peticionamento</h3>
                <button wire:click="cancelPetitionDate" class="text-mono-400 hover:text-mono-600 transition-colors">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>
            
            <div class="p-6 flex flex-col gap-5">
                <x-fx.input label="Data e hora do peticionamento" type="datetime-local" wire:model="promptPetitionAt" />
            </div>

            <div class="px-6 py-4 bg-mono-50 border-t border-mono-100 flex items-center justify-end gap-3">
                <button wire:click="cancelPetitionDate" class="px-4 py-2 text-sm font-bold text-mono-600 hover:text-mono-900 transition-colors">
                    Cancelar
                </button>
                <button wire:click="confirmPetitionDate" class="px-4 py-2 rounded-lg bg-primary-500 text-sm font-bold text-white hover:bg-primary-600 transition-colors">
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Aguardando Recebimento -->
    <div
        x-show="$wire.showAwaitingReceiptModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mono-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none;"
    >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden" @click.outside="$wire.cancelAwaitingReceiptDate()">
            <div class="px-6 py-4 border-b border-mono-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-mono-900">Confirmar Aguardando Recebimento</h3>
                <button wire:click="cancelAwaitingReceiptDate" class="text-mono-400 hover:text-mono-600 transition-colors">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>

            <div class="p-6 flex flex-col gap-5">
                <x-fx.input label="Data e hora para aguardar recebimento" type="datetime-local" wire:model="promptAwaitingReceiptAt" />
            </div>

            <div class="px-6 py-4 bg-mono-50 border-t border-mono-100 flex items-center justify-end gap-3">
                <button wire:click="cancelAwaitingReceiptDate" class="px-4 py-2 text-sm font-bold text-mono-600 hover:text-mono-900 transition-colors">
                    Cancelar
                </button>
                <button wire:click="confirmAwaitingReceiptDate" class="px-4 py-2 rounded-lg bg-primary-500 text-sm font-bold text-white hover:bg-primary-600 transition-colors">
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Finalização -->
    <div
        x-show="$wire.showFinalizedModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mono-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none;"
    >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden" @click.outside="$wire.cancelFinalizedDate()">
            <div class="px-6 py-4 border-b border-mono-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-mono-900">Confirmar Recebimento</h3>
                <button wire:click="cancelFinalizedDate" class="text-mono-400 hover:text-mono-600 transition-colors">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>
            
            <div class="p-6 flex flex-col gap-5">
                <x-fx.input label="Data do recebimento" type="date" wire:model="promptFinalizedAt" />
                <x-fx.input label="Valor recebido" type="text" x-money wire:model="promptActualReceiptAmount" />
                <div>
                    <label class="block text-sm font-medium text-mono-700 mb-1">Conta de destino</label>
                    <select wire:model="promptDestinationBankAccountId" class="fx-form-field">
                        <option value="">—</option>
                        @foreach (App\Domains\Banking\Models\BankAccount::all() as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-mono-700 mb-1">Nota da transição (opcional)</label>
                    <textarea wire:model="promptTransactionNote" class="fx-form-field" rows="3" placeholder="Digite uma observação..."></textarea>
                </div>
            </div>

            <div class="px-6 py-4 bg-mono-50 border-t border-mono-100 flex items-center justify-end gap-3">
                <button wire:click="cancelFinalizedDate" class="px-4 py-2 text-sm font-bold text-mono-600 hover:text-mono-900 transition-colors">
                    Cancelar
                </button>
                <button wire:click="confirmFinalizedDate" class="px-4 py-2 rounded-lg bg-primary-500 text-sm font-bold text-white hover:bg-primary-600 transition-colors">
                    Confirmar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Marcar como Perdido -->
    <div
        x-show="$wire.showLostModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-mono-900/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none;"
    >
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden" @click.outside="$wire.cancelLostReason()">
            <div class="px-6 py-4 border-b border-mono-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-mono-900">Marcar como perdido</h3>
                <button wire:click="cancelLostReason" class="text-mono-400 hover:text-mono-600 transition-colors">
                    <span class="material-icons-outlined">close</span>
                </button>
            </div>

            <div class="p-6 flex flex-col gap-5">
                <div>
                    <label class="block text-sm font-medium text-mono-700 mb-1">Motivo *</label>
                    <textarea wire:model="promptLostReason" class="fx-form-field" rows="4" required placeholder="Informe por que a negociação foi perdida"></textarea>
                    @error('promptLostReason') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="px-6 py-4 bg-mono-50 border-t border-mono-100 flex items-center justify-end gap-3">
                <button wire:click="cancelLostReason" class="px-4 py-2 text-sm font-bold text-mono-600 hover:text-mono-900 transition-colors">
                    Cancelar
                </button>
                <button wire:click="confirmLostReason" class="px-4 py-2 rounded-lg bg-primary-500 text-sm font-bold text-white hover:bg-primary-600 transition-colors">
                    Confirmar
                </button>
            </div>
        </div>
    </div>

</div>

@assets
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
@endassets

@script
<script>
    Alpine.data('writsKanban', () => ({
        init() {
            const lists = this.$el.querySelectorAll('.kanban-list');
            lists.forEach(list => {
                new Sortable(list, {
                    group: 'writs',
                    animation: 150,
                    ghostClass: 'opacity-50',
                    onAdd: (evt) => {
                        const id = parseInt(evt.item.dataset.id);
                        const newStage = evt.to.dataset.stage;
                        const oldStage = evt.from.dataset.stage;

                        if (oldStage === 'negotiation' && newStage === 'lost') {
                            evt.from.appendChild(evt.item);
                            $wire.promptLostReason(id);
                        } else if (oldStage === 'negotiation' && newStage === 'pending') {
                            evt.from.appendChild(evt.item);
                            $wire.promptCessionDate(id);
                        } else if (oldStage === 'pending' && newStage === 'paid') {
                            evt.from.appendChild(evt.item);
                            $wire.promptPaidDate(id);
                        } else if (oldStage === 'paid' && newStage === 'petitioning') {
                            evt.from.appendChild(evt.item);
                            $wire.promptPetitionDate(id);
                        } else if (newStage === 'awaiting_receipt') {
                            evt.from.appendChild(evt.item);
                            $wire.promptAwaitingReceiptDate(id);
                        } else if (oldStage === 'petitioning' && newStage === 'finalized') {
                            evt.from.appendChild(evt.item);
                            $wire.promptFinalizedDate(id);
                        } else if (oldStage === 'awaiting_receipt' && newStage === 'finalized') {
                            evt.from.appendChild(evt.item);
                            $wire.promptFinalizedDate(id);
                        } else {
                            $wire.moveCard(id, newStage);
                        }
                    },
                });
            });
        }
    }));
</script>
@endscript

<?php

use App\Domains\Assets\Models\Property;
use App\Domains\Assets\Models\PropertyDownPayment;
use App\Domains\Assets\Models\PropertyFinancing;
use App\Domains\Assets\Models\PropertyFinancingInstallment;
use App\Domains\Assets\Models\PropertyPayment;
use App\Domains\Assets\Services\PropertyFinancingService;
use App\Domains\Assets\Services\PropertyPaymentService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $kind = 'imovel';

    public string $description = '';

    public string $identifier = '';

    public string $acquired_at = '';

    public string $acquisition_value = '';

    public string $current_value = '';

    public string $current_value_date = '';

    public string $owner = '';

    public string $bank_account_id = '';

    /** Compra em sociedade: grupo do Divisões ligado ao bem ('' = compra só minha). */
    public string $division_group_id = '';

    public string $status = 'active';

    public string $sold_at = '';

    public string $sale_value = '';

    public string $notes = '';

    public $attachment = null;

    /** Bem com a seção Pagamentos aberta (?bem=13, usado pelo link "Bem: ..." do Financeiro). */
    #[Url(as: 'bem')]
    public ?int $payingId = null;

    public bool $showPayForm = false;

    public ?int $payMarkId = null;

    public string $payStatus = 'paid';

    public string $payDate = '';

    public string $payAmount = '';

    public string $payMethod = 'account';

    public string $payAccount = '';

    public string $payCard = '';

    public string $payInstallments = '1';

    public string $payCategory = '';

    public string $payNotes = '';

    public array $payWarnings = [];

    public bool $showDownForm = false;

    public string $downStatus = 'paid';

    public string $downDate = '';

    public string $downAmount = '';

    public string $downAccount = '';

    public string $downNotes = '';

    public array $downWarnings = [];

    public array $downDuplicates = [];

    public string $downLinkId = '';

    public bool $showFinancingForm = false;

    public string $finCreditor = '';

    public string $finAmount = '';

    public string $finCount = '';

    public string $finInstallment = '';

    public string $finFirstDue = '';

    public string $finRate = '';

    public string $finSystem = '';

    public string $finNotes = '';

    public array $finWarnings = [];

    public array $selectedInstallments = [];

    public bool $showInstPayForm = false;

    public ?int $payingInstallmentId = null;

    public string $instPaidAt = '';

    public string $instAccount = '';

    public string $instNotes = '';

    public array $instWarnings = [];

    public array $instDuplicates = [];

    public string $instLinkId = '';

    public bool $showBatchForm = false;

    public array $batchRows = [];

    public array $batchWarnings = [];

    public array $batchDuplicates = [];

    public string $batchFillDate = '';

    public string $batchFillAccount = '';

    public function openPayments(int $id): void
    {
        $this->payingId = Property::findOrFail($id)->id;
        $this->showPayForm = false;
        $this->resetPayForm();
        $this->resetFinancingForms();
    }

    public function closePayments(): void
    {
        $this->payingId = null;
        $this->showPayForm = false;
        $this->resetPayForm();
        $this->resetFinancingForms();
    }

    private function resetPayForm(): void
    {
        $this->reset(['payMarkId', 'payStatus', 'payDate', 'payAmount', 'payMethod', 'payAccount', 'payCard', 'payInstallments', 'payCategory', 'payNotes', 'payWarnings']);
        $this->resetValidation();
    }

    public function newPayment(): void
    {
        $property = Property::findOrFail($this->payingId);
        if ($property->isShared()) {
            // Compra em sociedade: o pagamento nasce no Divisões (um lançamento só na conta).
            $this->redirectRoute('divisions.show', ['group' => $property->division_group_id, 'nova' => 'compra']);

            return;
        }
        $this->resetPayForm();
        $this->payDate = \App\Support\BusinessDate::today();
        $this->payCategory = (string) Category::assetPurchaseCategoryId();
        $this->payAccount = (string) ($property->bank_account_id ?? '');
        $remaining = $property->paymentSummary()['remaining'] - $property->paymentSummary()['planned'];
        $this->payAmount = $remaining > 0 ? number_format($remaining, 2, '.', '') : '';
        $this->showPayForm = true;
    }

    /** "Paguei" num previsto: abre o formulário já preenchido; conta ou cartão, data e parcelas são escolhidos agora. */
    public function markPaidForm(int $paymentId): void
    {
        $payment = PropertyPayment::where('property_id', $this->payingId)->findOrFail($paymentId);
        $this->resetPayForm();
        $this->payMarkId = $payment->id;
        $this->payStatus = 'paid';
        $this->payDate = $payment->date?->toDateString() ?? \App\Support\BusinessDate::today();
        $this->payAmount = (string) $payment->amount;
        $this->payMethod = $payment->method ?? 'card';
        $this->payAccount = (string) ($payment->bank_account_id ?? $payment->property->bank_account_id ?? '');
        $this->payCard = (string) ($payment->credit_card_id ?? '');
        $this->payInstallments = (string) max(1, (int) $payment->installments);
        $this->payCategory = (string) ($payment->category_id ?? Category::assetPurchaseCategoryId());
        $this->payNotes = (string) $payment->notes;
        $this->showPayForm = true;
    }

    public function updatedPayStatus(): void
    {
        $this->payWarnings = [];
        if ($this->payStatus === 'paid' && $this->payMethod === '') {
            $this->payMethod = 'account';
        }
    }

    public function updatedPayMethod(): void
    {
        $this->payWarnings = [];
    }

    public function savePayment(bool $confirmed = false): void
    {
        $property = Property::findOrFail($this->payingId);
        $this->payAmount = \App\Support\Money::normalize($this->payAmount);
        $this->resetValidation();
        $data = [
            'status' => $this->payMarkId ? 'paid' : $this->payStatus,
            'date' => $this->payDate,
            'amount' => $this->payAmount,
            'method' => $this->payMethod,
            'bank_account_id' => $this->payAccount,
            'credit_card_id' => $this->payCard,
            'installments' => $this->payInstallments,
            'category_id' => $this->payCategory,
            'notes' => $this->payNotes,
        ];
        $service = app(PropertyPaymentService::class);
        $amount = (float) $this->payAmount;
        if (! $confirmed && $amount > 0) {
            $warnings = [];
            if ($over = $service->exceedsTotal($property, $amount, $this->payMarkId)) {
                $warnings[] = 'Com este pagamento, pago + previsto passa do valor total da compra ('.$this->brl((float) $property->acquisition_value).') em '.$this->brl($over).'.';
            }
            if ($data['status'] === 'paid' && $this->payMethod === 'account' && ctype_digit($this->payAccount) && $this->payDate !== '') {
                foreach ($service->possibleDuplicates((int) $this->payAccount, $amount, $this->payDate) as $dup) {
                    $warnings[] = 'Já existe nesta conta um lançamento de '.$this->brl(abs((float) $dup->amount)).' em '.$dup->date->format('d/m/Y').' ("'.mb_substr((string) $dup->description, 0, 50).'"), talvez vindo do extrato importado. Se for este mesmo pagamento, não lance de novo.';
                }
            }
            if ($warnings) {
                $this->payWarnings = $warnings;

                return;
            }
        }
        try {
            $this->payMarkId
                ? $service->markPaid(PropertyPayment::where('property_id', $property->id)->findOrFail($this->payMarkId), $data)
                : $service->record($property, $data);
        } catch (\DomainException $e) {
            $this->payWarnings = [];
            $this->addError('payForm', $e->getMessage());

            return;
        }
        $this->showPayForm = false;
        $this->resetPayForm();
        session()->flash('status', 'Pagamento registrado.');
    }

    public function deletePayment(int $paymentId): void
    {
        $payment = PropertyPayment::where('property_id', $this->payingId)->findOrFail($paymentId);
        try {
            app(PropertyPaymentService::class)->delete($payment);
        } catch (\DomainException $e) {
            $this->addError('payForm', $e->getMessage());

            return;
        }
        session()->flash('status', 'Pagamento apagado, junto com o lançamento ligado a ele.');
    }

    private function brl(float $v): string
    {
        return 'R$ '.number_format($v, 2, ',', '.');
    }


    private function resetFinancingForms(): void
    {
        $this->reset([
            'showDownForm', 'downStatus', 'downDate', 'downAmount', 'downAccount', 'downNotes', 'downWarnings', 'downDuplicates', 'downLinkId',
            'showFinancingForm', 'finCreditor', 'finAmount', 'finCount', 'finInstallment', 'finFirstDue', 'finRate', 'finSystem', 'finNotes', 'finWarnings',
            'selectedInstallments', 'showInstPayForm', 'payingInstallmentId', 'instPaidAt', 'instAccount', 'instNotes', 'instWarnings', 'instDuplicates', 'instLinkId',
            'showBatchForm', 'batchRows', 'batchWarnings', 'batchDuplicates', 'batchFillDate', 'batchFillAccount',
        ]);
        $this->downStatus = 'paid';
    }

    private function serializeDuplicates($rows): array
    {
        return $rows->map(fn ($t) => [
            'id' => $t->id,
            'date' => $t->date->format('d/m/Y'),
            'amount' => abs((float) $t->amount),
            'description' => mb_substr((string) $t->description, 0, 60),
        ])->all();
    }

    public function newDownPayment(): void
    {
        $property = Property::findOrFail($this->payingId);
        $this->reset(['downWarnings', 'downDuplicates', 'downLinkId', 'downNotes', 'downAmount', 'downAccount']);
        $this->downStatus = 'paid';
        $this->downDate = $property->acquired_at?->toDateString() ?: \App\Support\BusinessDate::today();
        $this->downAccount = (string) ($property->bank_account_id ?? '');
        $this->showDownForm = true;
    }

    public function saveDownPayment(bool $confirmed = false): void
    {
        $property = Property::findOrFail($this->payingId);
        $this->downAmount = \App\Support\Money::normalize($this->downAmount);
        $this->resetValidation();
        $data = [
            'status' => 'paid',
            'date' => $this->downDate,
            'amount' => $this->downAmount,
            'bank_account_id' => $this->downAccount,
            'notes' => $this->downNotes,
        ];
        $link = ctype_digit((string) $this->downLinkId) ? (int) $this->downLinkId : null;
        try {
            $result = app(PropertyFinancingService::class)->addDownPayment($property, $data, confirmed: $confirmed, linkTransactionId: $link);
        } catch (\DomainException $e) {
            $this->downWarnings = [];
            $this->addError('downForm', $e->getMessage());

            return;
        }
        if (is_array($result)) {
            $this->downWarnings = $result['warnings'];
            $this->downDuplicates = $this->serializeDuplicates($result['duplicates']);

            return;
        }
        $this->showDownForm = false;
        $this->reset(['downWarnings', 'downDuplicates', 'downLinkId', 'downAmount', 'downNotes']);
        session()->flash('status', 'Entrada registrada.');
    }

    public function deleteDownPayment(int $id): void
    {
        $down = PropertyDownPayment::where('property_id', $this->payingId)->findOrFail($id);
        try {
            app(PropertyFinancingService::class)->deleteDownPayment($down);
        } catch (\DomainException $e) {
            $this->addError('downForm', $e->getMessage());

            return;
        }
        session()->flash('status', 'Entrada apagada.');
    }

    public function newFinancing(): void
    {
        $this->reset(['finCreditor', 'finAmount', 'finCount', 'finInstallment', 'finFirstDue', 'finRate', 'finSystem', 'finNotes', 'finWarnings']);
        $this->showFinancingForm = true;
    }

    public function saveFinancing(bool $confirmed = false): void
    {
        $property = Property::findOrFail($this->payingId);
        $this->finAmount = \App\Support\Money::normalize($this->finAmount);
        $this->finInstallment = \App\Support\Money::normalize($this->finInstallment);
        $this->resetValidation();
        $rate = trim(str_replace(',', '.', $this->finRate));
        $data = [
            'creditor' => $this->finCreditor,
            'financed_amount' => $this->finAmount,
            'installment_count' => $this->finCount,
            'installment_amount' => $this->finInstallment,
            'first_due_date' => $this->finFirstDue,
            'interest_rate_am' => $rate,
            'amortization_system' => $this->finSystem,
            'notes' => $this->finNotes,
        ];
        if (! $confirmed && $property->acquisition_value !== null && is_numeric($this->finAmount)) {
            $down = (int) $property->downPayments()->get()->sum(fn ($d) => (int) round((float) $d->amount * 100));
            $fin = (int) round((float) $this->finAmount * 100);
            $price = (int) round((float) $property->acquisition_value * 100);
            $diff = $down + $fin - $price;
            if ($diff !== 0) {
                $this->finWarnings = ['A entrada mais o valor financiado ('.$this->brl(($down + $fin) / 100).') não fecha com o preço do bem ('.$this->brl($price / 100).'). Diferença: '.$this->brl($diff / 100).'.'];

                return;
            }
        }
        try {
            app(PropertyFinancingService::class)->createFinancing($property, $data);
        } catch (\DomainException $e) {
            $this->finWarnings = [];
            $this->addError('finForm', $e->getMessage());

            return;
        }
        $this->showFinancingForm = false;
        $this->finWarnings = [];
        session()->flash('status', 'Financiamento criado e parcelas geradas.');
    }

    public function deleteFinancing(int $id): void
    {
        $fin = PropertyFinancing::where('property_id', $this->payingId)->findOrFail($id);
        try {
            app(PropertyFinancingService::class)->deleteFinancing($fin);
        } catch (\DomainException $e) {
            $this->addError('finForm', $e->getMessage());

            return;
        }
        session()->flash('status', 'Financiamento apagado.');
    }

    public function markInstallmentForm(int $id): void
    {
        $inst = PropertyFinancingInstallment::whereHas('financing', fn ($q) => $q->where('property_id', $this->payingId))->findOrFail($id);
        $this->reset(['instWarnings', 'instDuplicates', 'instLinkId', 'instNotes', 'showBatchForm']);
        $this->payingInstallmentId = $inst->id;
        $this->instPaidAt = $inst->due_date?->toDateString() ?? \App\Support\BusinessDate::today();
        $this->instAccount = (string) ($inst->bank_account_id ?? '');
        $this->showInstPayForm = true;
    }

    public function saveInstallmentPaid(bool $confirmed = false): void
    {
        $inst = PropertyFinancingInstallment::whereHas('financing', fn ($q) => $q->where('property_id', $this->payingId))->findOrFail($this->payingInstallmentId);
        $this->resetValidation();
        $link = ctype_digit((string) $this->instLinkId) ? (int) $this->instLinkId : null;
        try {
            $result = app(PropertyFinancingService::class)->markInstallmentPaid($inst, [
                'paid_at' => $this->instPaidAt,
                'bank_account_id' => $this->instAccount,
                'notes' => $this->instNotes,
            ], confirmed: $confirmed, linkTransactionId: $link);
        } catch (\DomainException $e) {
            $this->instWarnings = [];
            $this->addError('instForm', $e->getMessage());

            return;
        }
        if (is_array($result)) {
            $this->instWarnings = $result['warnings'];
            $this->instDuplicates = $this->serializeDuplicates($result['duplicates']);

            return;
        }
        $this->showInstPayForm = false;
        $this->payingInstallmentId = null;
        $this->reset(['instWarnings', 'instDuplicates', 'instLinkId']);
        session()->flash('status', 'Parcela marcada como paga.');
    }

    public function unpayInstallment(int $id): void
    {
        $inst = PropertyFinancingInstallment::whereHas('financing', fn ($q) => $q->where('property_id', $this->payingId))->findOrFail($id);
        try {
            app(PropertyFinancingService::class)->unpayInstallment($inst);
        } catch (\DomainException $e) {
            $this->addError('instForm', $e->getMessage());

            return;
        }
        session()->flash('status', 'Pagamento da parcela desfeito.');
    }

    public function openBatchForm(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selectedInstallments)));
        if (! $ids) {
            $this->addError('instForm', 'Selecione ao menos uma parcela em aberto.');

            return;
        }
        $rows = PropertyFinancingInstallment::whereHas('financing', fn ($q) => $q->where('property_id', $this->payingId))
            ->whereIn('id', $ids)->where('status', 'open')->orderBy('number')->get();
        if ($rows->isEmpty()) {
            $this->addError('instForm', 'Selecione ao menos uma parcela em aberto.');

            return;
        }
        $this->batchRows = [];
        foreach ($rows as $i) {
            $this->batchRows[(string) $i->id] = [
                'number' => $i->number,
                'due' => $i->due_date->format('d/m/Y'),
                'amount' => (float) $i->amount,
                'paid_at' => $i->due_date->toDateString(),
                'account' => (string) ($i->bank_account_id ?? ''),
                'link' => '',
            ];
        }
        $this->batchWarnings = [];
        $this->batchDuplicates = [];
        $this->batchFillDate = \App\Support\BusinessDate::today();
        $this->batchFillAccount = '';
        $this->showBatchForm = true;
        $this->showInstPayForm = false;
    }

    public function applyBatchFill(): void
    {
        foreach ($this->batchRows as $id => $row) {
            if ($this->batchFillDate !== '') {
                $this->batchRows[$id]['paid_at'] = $this->batchFillDate;
            }
            $this->batchRows[$id]['account'] = $this->batchFillAccount;
        }
    }

    public function saveBatch(): void
    {
        $payload = [];
        foreach ($this->batchRows as $id => $row) {
            $payload[(int) $id] = [
                'paid_at' => $row['paid_at'] ?? '',
                'bank_account_id' => $row['account'] ?? '',
                'link_transaction_id' => ! empty($row['link']) ? (int) $row['link'] : null,
            ];
        }
        $confirmed = $this->batchWarnings !== [];
        try {
            $result = app(PropertyFinancingService::class)->markInstallmentsPaid($payload, confirmed: $confirmed);
        } catch (\DomainException $e) {
            $this->addError('instForm', $e->getMessage());

            return;
        }
        if (is_array($result) && ($result['ok'] ?? true) === false) {
            $this->batchWarnings = $result['warnings'];
            $this->batchDuplicates = [];
            foreach ($result['duplicates'] as $id => $dups) {
                $this->batchDuplicates[$id] = $this->serializeDuplicates($dups);
            }

            return;
        }
        $this->showBatchForm = false;
        $this->selectedInstallments = [];
        $this->batchRows = [];
        $this->batchWarnings = [];
        $this->batchDuplicates = [];
        session()->flash('status', 'Parcelas marcadas como pagas.');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Property::findOrFail($id);
        $this->resetForm();
        $this->editingId = $p->id;
        foreach (['kind', 'description', 'identifier', 'owner', 'status', 'notes'] as $f) {
            $this->{$f} = (string) $p->{$f};
        }
        foreach (['acquired_at', 'current_value_date', 'sold_at'] as $f) {
            $this->{$f} = $p->{$f}?->toDateString() ?? '';
        }
        foreach (['acquisition_value', 'current_value', 'sale_value'] as $f) {
            $this->{$f} = $p->{$f} === null ? '' : (string) $p->{$f};
        }
        $this->bank_account_id = (string) ($p->bank_account_id ?? '');
        $this->division_group_id = (string) ($p->division_group_id ?? '');
        $this->showForm = true;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'kind', 'description', 'identifier', 'acquired_at', 'acquisition_value', 'current_value', 'current_value_date', 'owner', 'bank_account_id', 'division_group_id', 'status', 'sold_at', 'sale_value', 'notes', 'attachment']);
        $this->resetValidation();
    }

    public function save(): void
    {
        foreach (['acquisition_value', 'current_value', 'sale_value'] as $f) {
            $this->{$f} = \App\Support\Money::normalize($this->{$f});
        }
        $data = $this->validate([
            'kind' => ['required', Rule::in(array_keys(Property::KINDS))],
            'description' => ['required', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'acquired_at' => ['nullable', 'date'],
            'acquisition_value' => ['nullable', 'numeric', 'min:0'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'current_value_date' => ['nullable', 'date'],
            'owner' => ['nullable', Rule::in(array_keys(Property::OWNERS))],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'division_group_id' => ['nullable', 'integer', 'exists:division_groups,id'],
            'status' => ['required', Rule::in(['active', 'sold'])],
            'sold_at' => ['nullable', 'required_if:status,sold', 'date'],
            'sale_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        unset($data['attachment']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $property = $this->editingId ? Property::findOrFail($this->editingId) : new Property;
        if ($data['division_group_id'] ?? null) {
            if (! auth()->user()?->isAdmin()) {
                $this->addError('division_group_id', 'Só o Cassio liga um bem a um grupo do Divisões.');

                return;
            }
            if ($property->exists && (int) $property->division_group_id !== (int) $data['division_group_id'] && $property->payments()->exists()) {
                $this->addError('division_group_id', 'Este bem já tem pagamentos próprios. Apague-os antes de ligar a compra a um grupo do Divisões.');

                return;
            }
            if (Property::where('division_group_id', $data['division_group_id'])->whereKeyNot($property->id ?? 0)->exists()) {
                $this->addError('division_group_id', 'Este grupo já está ligado a outro bem.');

                return;
            }
        } elseif ($property->exists && $property->division_group_id && ! auth()->user()?->isAdmin()) {
            $data['division_group_id'] = $property->division_group_id;
        }
        $property->fill($data)->save();
        if ($this->attachment) {
            if ($property->attachment_path) {
                Storage::disk('local')->delete($property->attachment_path);
            }
            $path = $this->attachment->storeAs('bens/'.$property->id, Str::uuid().'.'.$this->attachment->getClientOriginalExtension(), 'local');
            $property->update(['attachment_path' => $path, 'attachment_original_name' => $this->attachment->getClientOriginalName(), 'attachment_mime' => $this->attachment->getMimeType(), 'attachment_size' => $this->attachment->getSize()]);
        }
        app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class)->refresh();
        $this->showForm = false;
        session()->flash('status', $this->editingId ? 'Bem atualizado.' : 'Bem cadastrado.');
        $this->resetForm();
    }

    public function removeAttachment(int $id): void
    {
        $p = Property::findOrFail($id);
        if ($p->attachment_path) {
            Storage::disk('local')->delete($p->attachment_path);
        }
        $p->update(['attachment_path' => null, 'attachment_original_name' => null, 'attachment_mime' => null, 'attachment_size' => null]);
    }

    public function delete(int $id): void
    {
        $property = Property::findOrFail($id);
        if ($property->payments()->where('status', 'paid')->exists()
            || $property->downPayments()->where('status', 'paid')->exists()
            || $property->financings()->whereHas('installments', fn ($q) => $q->where('status', 'paid'))->exists()) {
            session()->flash('error', 'Este bem tem pagamentos ou parcelas lançadas. Apague-os antes (isso também apaga os lançamentos ligados).');

            return;
        }
        $property->delete();
        if ($this->payingId === $id) {
            $this->closePayments();
        }
        app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class)->refresh();
        session()->flash('status', 'Bem excluído.');
    }

    public function with(): array
    {
        return [
            'properties' => Property::with(['bankAccount', 'payments', 'downPayments', 'financings.installments'])->orderBy('status')->orderBy('description')->get(),
            'total' => Property::activeTotal(),
            'financingDebt' => Property::activeFinancingDebt(),
            'accounts' => BankAccount::orderBy('name')->get(['id', 'name']),
            'divisionGroups' => auth()->user()?->isAdmin() ? \App\Domains\Divisions\Models\DivisionGroup::where('status', 'approved')->orderBy('name')->get(['id', 'name']) : collect(),
            'paying' => $this->payingId ? Property::with(['payments.bankAccount', 'payments.creditCard', 'payments.category', 'payments.transactions', 'downPayments.bankAccount', 'downPayments.transaction', 'financings.installments.bankAccount', 'financings.installments.transaction'])->find($this->payingId) : null,
            'payAccounts' => BankAccount::active()->whereNot('type', 'repasse')->orderBy('name')->get(['id', 'name']),
            'payCards' => CreditCard::active()->orderBy('name')->get(['id', 'name']),
            'payCategories' => Category::whereKey(Category::assetPurchaseCategoryId())->get(['id', 'name'])
                ->concat(Category::where('type', 'expense')->where('status', true)->orderBy('name')->get(['id', 'name'])),
        ];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>

@php $m = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.'); $in = 'h-10 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 w-full'; @endphp

<div class="flex flex-col gap-space-5">
    <x-investments.subnav />
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif
    @if ($paying)
        @if ($paying->isShared())
            @include('bens.partials.society', ['p' => $paying])
        @else
            @include('bens.partials.financing', ['p' => $paying])
            @include('bens.partials.payments', ['p' => $paying])
        @endif
    @endif
    <x-fx.card>
        <div class="flex flex-wrap items-center gap-space-3">
            <div>
                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Bens</h3>
                <div class="text-fs-12 text-cryptex-text-secondary">Imóveis, veículos e outros bens. O cadastro não cria lançamento; os pagamentos de cada bem saem da conta ou entram na fatura, fora do resultado do mês.</div>
            </div>
            <div class="ml-auto text-right"><div class="text-fs-12 text-cryptex-text-tertiary uppercase">Total em bens ativos</div><div class="text-fs-20 font-bold font-mono">{{ $m($total) }}</div>@if ($financingDebt > 0)<div class="text-fs-12 text-cryptex-text-secondary">Dívidas de financiamento: {{ $m($financingDebt) }}</div><div class="text-fs-12 text-cryptex-text-secondary">Patrimônio líquido dos bens: {{ $m($total - $financingDebt) }}</div>@endif</div>
            <button type="button" wire:click="create" class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Novo bem</button>
        </div>
        @if ($properties->isEmpty())
            <div class="py-10 text-center text-sm text-mono-600" data-empty="bens">Nenhum bem cadastrado.</div>
        @else
        <div class="mt-space-3 space-y-3 md:hidden" data-mobile="bens">
            @foreach ($properties as $p)
                <div class="rounded-sm border border-cryptex-border-default p-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0"><strong class="break-words">{{ $p->description }}</strong><div class="text-xs text-mono-600">{{ \App\Domains\Assets\Models\Property::KINDS[$p->kind] ?? $p->kind }}@if ($p->identifier) · {{ $p->identifier }}@endif</div></div>
                        <div class="text-right"><strong>{{ $m($p->status === 'sold' && $p->sale_value !== null ? $p->sale_value : $p->current_value) }}</strong><div class="text-xs {{ $p->status === 'sold' ? 'text-system-down' : 'text-mono-600' }}">{{ $p->status === 'sold' ? 'Vendido'.($p->sold_at ? ' em '.$p->sold_at->format('d/m/Y') : '') : 'Ativo' }}</div></div>
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        @if ($p->owner)<dt class="text-mono-600">Titular</dt><dd class="text-right">{{ \App\Domains\Assets\Models\Property::OWNERS[$p->owner] ?? $p->owner }}</dd>@endif
                        @if ($p->bankAccount)<dt class="text-mono-600">Conta</dt><dd class="text-right">{{ $p->bankAccount->name }}</dd>@endif
                        <dt class="text-mono-600">Valor atual</dt><dd class="text-right">{{ $m($p->current_value) }}@if ($p->current_value_date) · {{ $p->current_value_date->format('d/m/Y') }}@endif</dd>
                        <dt class="text-mono-600">{{ $p->payments->isNotEmpty() ? 'Total da compra' : 'Aquisição' }}</dt><dd class="text-right">{{ $m($p->acquisition_value) }}@if ($p->acquired_at) · {{ $p->acquired_at->format('d/m/Y') }}@endif</dd>
                    </dl>
                    @if ($p->isShared())@include('bens.partials.society-bar', ['p' => $p])@elseif ($p->payments->isNotEmpty())@include('bens.partials.bar', ['s' => $p->paymentSummary()])@endif
                    @if ($p->remainingDebt() > 0)<div class="mt-1 text-xs text-cryptex-text-secondary">Saldo devedor: {{ $m($p->remainingDebt()) }}</div>@endif
                    <div class="mt-2 flex gap-4 text-xs">
                        @if ($p->hasAttachment())<a class="text-cryptex-brand-400" href="{{ route('bens.attachment', $p) }}">Baixar anexo</a>@endif
                        <button type="button" wire:click="openPayments({{ $p->id }})" class="text-cryptex-brand-400" data-open-payments="{{ $p->id }}">Pagamentos</button>
                        <button type="button" wire:click="edit({{ $p->id }})" class="ml-auto text-cryptex-brand-400">Editar</button>
                        <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Excluir este bem?" class="text-system-down">Excluir</button>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-space-3 hidden overflow-x-auto md:block">
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Bem</th><th class="text-left">Tipo</th><th class="text-left">Titular</th><th class="text-right">Aquisição / total</th><th class="text-right">Valor atual</th><th class="text-left">Situação</th><th class="text-left">Anexo</th><th></th></tr></thead>
                <tbody>
                    @foreach ($properties as $p)
                        <tr>
                            <td><strong>{{ $p->description }}</strong>@if ($p->identifier)<div class="text-xs text-mono-600">{{ $p->identifier }}</div>@endif</td>
                            <td>{{ \App\Domains\Assets\Models\Property::KINDS[$p->kind] ?? $p->kind }}</td>
                            <td>{{ \App\Domains\Assets\Models\Property::OWNERS[$p->owner] ?? 'Não informado' }}</td>
                            <td class="text-right">{{ $m($p->acquisition_value) }}<div class="text-xs text-mono-600">{{ $p->acquired_at?->format('d/m/Y') }}</div>@if ($p->isShared())<div class="min-w-[170px] text-left">@include('bens.partials.society-bar', ['p' => $p])</div>@elseif ($p->payments->isNotEmpty())<div class="min-w-[170px] text-left">@include('bens.partials.bar', ['s' => $p->paymentSummary()])</div>@endif</td>
                            <td class="text-right">{{ $m($p->current_value) }}<div class="text-xs text-mono-600">{{ $p->current_value_date?->format('d/m/Y') }}</div>@if ($p->remainingDebt() > 0)<div class="text-xs text-cryptex-text-secondary">Dívida {{ $m($p->remainingDebt()) }}</div>@endif</td>
                            <td>{{ $p->status === 'sold' ? 'Vendido'.($p->sold_at ? ' em '.$p->sold_at->format('d/m/Y') : '') : 'Ativo' }}</td>
                            <td>@if ($p->hasAttachment())<a class="text-cryptex-brand-400" href="{{ route('bens.attachment', $p) }}">Baixar</a>@else — @endif</td>
                            <td class="text-right whitespace-nowrap"><button type="button" wire:click="openPayments({{ $p->id }})" class="text-cryptex-brand-400 mr-2">Pagamentos</button><button type="button" wire:click="edit({{ $p->id }})" class="text-cryptex-brand-400">Editar</button> <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Excluir este bem?" class="text-system-down ml-2">Excluir</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-fx.card>

    @if ($showForm)
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-3">{{ $editingId ? 'Editar bem' : 'Novo bem' }}</h3>
            <form wire:submit="save" class="grid gap-space-3 md:grid-cols-3 text-sm">
                <label class="flex flex-col gap-1">Tipo<select wire:model="kind" class="{{ $in }}">@foreach (\App\Domains\Assets\Models\Property::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-1 md:col-span-2">Descrição<input wire:model="description" class="{{ $in }}" />@error('description')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1">Identificação (matrícula, placa)<input wire:model="identifier" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Titular<select wire:model="owner" class="{{ $in }}"><option value="">Não informado</option>@foreach (\App\Domains\Assets\Models\Property::OWNERS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-1">Conta vinculada (opcional)<select wire:model="bank_account_id" class="{{ $in }}"><option value="">Nenhuma</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                @if ($divisionGroups->isNotEmpty())
                    <label class="flex flex-col gap-1">Compra em sociedade (grupo do Divisões)<select wire:model="division_group_id" class="{{ $in }}" data-society-group><option value="">Não — compra só minha</option>@foreach ($divisionGroups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>@error('division_group_id')<span class="text-system-down text-xs">{{ $message }}</span>@enderror<span class="text-xs text-cryptex-text-tertiary">As parcelas são lançadas no Divisões como "Compra do bem"; o patrimônio conta só a sua parte.</span></label>
                @endif
                <label class="flex flex-col gap-1">Data de aquisição<input type="date" wire:model="acquired_at" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Valor total da compra (aquisição)<input type="text" x-money wire:model="acquisition_value" class="{{ $in }}" placeholder="0,00" />@error('acquisition_value')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <span></span>
                <label class="flex flex-col gap-1">Valor atual<input type="text" x-money wire:model="current_value" class="{{ $in }}" placeholder="0,00" />@error('current_value')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1">Data do valor atual<input type="date" wire:model="current_value_date" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Situação<select wire:model.live="status" class="{{ $in }}"><option value="active">Ativo</option><option value="sold">Vendido</option></select></label>
                @if ($status === 'sold')
                    <label class="flex flex-col gap-1">Data da venda<input type="date" wire:model="sold_at" class="{{ $in }}" />@error('sold_at')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                    <label class="flex flex-col gap-1">Valor da venda<input type="text" x-money wire:model="sale_value" class="{{ $in }}" placeholder="0,00" /></label>
                    <span></span>
                @endif
                <label class="flex flex-col gap-1 md:col-span-2">Observações<textarea wire:model="notes" rows="2" class="rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 py-1"></textarea></label>
                <label class="flex flex-col gap-1">Anexo (PDF, JPG, PNG até 10 MB — privado)<input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" />@error('attachment')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <div class="md:col-span-3 flex gap-space-3">
                    <button type="submit" class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Salvar</button>
                    <button type="button" wire:click="$set('showForm', false)" class="min-h-[40px] px-3 rounded-sm border border-cryptex-border-default">Cancelar</button>
                    @if ($editingId && \App\Domains\Assets\Models\Property::find($editingId)?->hasAttachment())
                        <button type="button" wire:click="removeAttachment({{ $editingId }})" class="min-h-[40px] px-3 text-system-down">Remover anexo</button>
                    @endif
                </div>
            </form>
        </x-fx.card>
    @endif
</div>

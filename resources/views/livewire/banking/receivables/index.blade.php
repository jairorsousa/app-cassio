<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceivableService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url] public string $contact = '';
    #[Url] public string $search = '';
    #[Url] public string $testes = '';
    #[Url] public string $status = 'open';
    #[Url] public string $transaction = '';
    #[Locked] public ?int $selectedId = null;
    #[Locked] public string $createKey = '';
    #[Locked] public string $paymentKey = '';
    public bool $creating = false;
    public string $newContactName = '';
    public string $sourceSearch = '';
    public array $form = [];
    public array $payment = [];
    public array $dates = [];
    public bool $editingCharges = false;
    public array $charges = [];

    public function mount(): void
    {
        $this->resetCreate();
        if ($this->transaction !== '') {
            $this->startCreate((int) $this->transaction);

            return;
        }
        $this->applyCreateDraft(request()->query());
    }

    /** Prefill a partir do atalho "Paguei por alguém" no modal de lançamento (sem criar saída duplicada). */
    private function applyCreateDraft(array $query): void
    {
        $origin = $query['origin'] ?? null;
        $criar = ($query['criar'] ?? '') === '1';
        if (! $criar && ! in_array($origin, ['bank', 'card'], true)) {
            return;
        }
        $this->startCreate();
        if (in_array($origin, ['bank', 'card'], true)) {
            $this->form['origin'] = $origin;
            $this->form['use_invoice_dates'] = $origin === 'card';
        }
        foreach (['date', 'description', 'amount', 'notes', 'process_reference'] as $field) {
            if (! empty($query[$field]) && is_string($query[$field])) {
                $this->form[$field] = mb_substr($query[$field], 0, $field === 'notes' ? 5000 : 200);
            }
        }
        if (isset($query['installments']) && is_numeric($query['installments'])) {
            $this->form['installments'] = max(1, min(120, (int) $query['installments']));
        }
        if (! empty($query['bank_account_id']) && is_numeric($query['bank_account_id'])) {
            $this->form['bank_account_id'] = (int) $query['bank_account_id'];
        }
        if (! empty($query['credit_card_id']) && is_numeric($query['credit_card_id'])) {
            $this->form['credit_card_id'] = (int) $query['credit_card_id'];
        }
        if ($this->form['amount'] !== '') {
            $this->form['people'][0]['amount'] = $this->form['amount'];
        }
        if (! empty($query['contact']) && is_numeric($query['contact'])) {
            $this->form['people'][0]['contact_id'] = (int) $query['contact'];
            $this->contact = (string) (int) $query['contact'];
        }
    }

    public function updatedContact(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    private function resetCreate(): void
    {
        $this->createKey = (string) Str::uuid();
        $today = \App\Support\BusinessDate::today();
        $this->form = [
            'origin' => 'bank', 'date' => $today, 'description' => '', 'amount' => '',
            'bank_account_id' => null, 'credit_card_id' => null, 'transaction_id' => null, 'installments' => 1,
            'repayment_mode' => 'dated', 'first_due_date' => \Illuminate\Support\Carbon::parse($today)->addMonthNoOverflow()->toDateString(),
            'use_invoice_dates' => false, 'writ_id' => null, 'process_reference' => '', 'notes' => '',
            'interest_rate_am' => '0', 'interest_type' => 'simple', 'penalty_pct' => '0', 'late_interest_rate_am' => '0', 'late_interest_type' => 'simple', 'interest_start_date' => '',
            'people' => [['contact_id' => $this->contact ?: null, 'amount' => '']],
        ];
    }

    public function startCreate(?int $transactionId = null): void
    {
        $this->resetValidation();
        $this->resetCreate();
        $this->creating = true;
        $this->selectedId = null;
        $this->sourceSearch = '';
        if ($transactionId) { $this->form['origin'] = 'existing'; $this->form['transaction_id'] = $transactionId; $this->loadSource(); }
    }

    public function updatedFormOrigin(): void
    {
        $this->form['use_invoice_dates'] = $this->form['origin'] === 'card';
    }

    public function createPersonalContact(): void
    {
        $this->validate(['newContactName' => 'required|string|max:200']);
        $index = collect($this->form['people'])->search(fn ($person) => empty($person['contact_id']));
        if ($index === false) {
            if (count($this->form['people']) >= 20) {
                $this->addError('newContactName', 'O limite é de 20 pessoas por pagamento.');
                return;
            }
            $this->addPerson();
            $index = count($this->form['people']) - 1;
        }
        $contact = Contact::create(['name' => trim($this->newContactName), 'type' => 'pessoal', 'status' => true]);
        $this->form['people'][$index]['contact_id'] = $contact->id;
        $this->newContactName = '';
    }

    public function loadIncoming(): void
    {
        $entry = Transaction::where('type', 'income')->where('status', 'settled')->find($this->payment['transaction_id'] ?? null);
        if ($entry) {
            $this->payment['amount'] = (string) $entry->amount;
            $this->payment['date'] = $entry->date->toDateString();
            $this->payment['bank_account_id'] = $entry->bank_account_id;
        }
    }

    public function loadSource(): void
    {
        $source = Transaction::find($this->form['transaction_id'] ?? null);
        if (! $source) { return; }
        if ($source->installment_group_id) { $source = Transaction::where('installment_group_id', $source->installment_group_id)->orderBy('installment_number')->firstOrFail(); }
        $this->form['use_invoice_dates'] = (bool) $source->credit_card_invoice_id;
        $total = $source->installment_group_id ? Transaction::where('installment_group_id', $source->installment_group_id)->sum('amount') : $source->amount;
        $this->form['amount'] = number_format((float) $total, 2, '.', '');
        $this->form['date'] = min($source->date->toDateString(), \App\Support\BusinessDate::today());
        $this->form['description'] = $source->description;
        $this->form['installments'] = $source->credit_card_id ? 1 : $this->form['installments'];
        $this->form['people'][0]['amount'] = $this->form['amount'];
        if ($source->invoice) { $this->form['first_due_date'] = $source->invoice->due_date->toDateString(); }
    }

    public function addPerson(): void
    {
        if (count($this->form['people']) < 20) { $this->form['people'][] = ['contact_id' => null, 'amount' => '']; }
    }

    public function removePerson(int $index): void
    {
        if (count($this->form['people']) > 1 && isset($this->form['people'][$index])) { array_splice($this->form['people'], $index, 1); }
    }

    public function cancelCreate(): void { $this->creating = false; $this->resetValidation(); }


    private function formAmountCents(): int
    {
        $raw = $this->form['people'][0]['amount'] ?? $this->form['amount'] ?? '';
        if ($raw === '' || $raw === null) {
            return 0;
        }
        $normalized = \App\Support\Money::normalize((string) $raw);
        if ($normalized === '') {
            return 0;
        }

        return (int) round((float) $normalized * 100);
    }

    private function interestPreview(): ?array
    {
        $raw = (string) ($this->form['interest_rate_am'] ?? '0');
        $rate = (float) ((\App\Support\Money::decimal($raw, 4) ?: 0));
        $start = ($this->form['interest_start_date'] ?? '') ?: ($this->form['date'] ?? '');
        if ($this->formAmountCents() <= 0 || $rate <= 0 || ! $start) {
            return null;
        }

        return \App\Support\SimpleInterest::previewTotals(
            $this->formAmountCents(),
            $start,
            ($this->form['first_due_date'] ?? '') ?: null,
            $rate,
        );
    }

    private function lateInterestPreview(): ?array
    {
        $raw = (string) ($this->form['late_interest_rate_am'] ?? '0');
        $rate = (float) ((\App\Support\Money::decimal($raw, 4) ?: 0));

        return \App\Support\SimpleInterest::previewLateTotals($this->formAmountCents(), $rate);
    }

    private function chargesPreview(): ?array
    {
        $raw = (string) ($this->charges['interest_rate_am'] ?? '0');
        $rate = (float) ((\App\Support\Money::decimal($raw, 4) ?: 0));
        $start = ($this->charges['interest_start_date'] ?? '') ?: '';
        $selected = $this->selectedId ? Receivable::with('installments')->find($this->selectedId) : null;
        $principal = $selected?->amountCents() ?? 0;
        if ($principal <= 0 || $rate <= 0 || ! $start) {
            return null;
        }

        return \App\Support\SimpleInterest::previewTotals($principal, $start, $selected?->dueDateForCharges(), $rate);
    }

    private function chargesLatePreview(): ?array
    {
        $raw = (string) ($this->charges['late_interest_rate_am'] ?? '0');
        $rate = (float) ((\App\Support\Money::decimal($raw, 4) ?: 0));
        $selected = $this->selectedId ? Receivable::with('installments')->find($this->selectedId) : null;

        return \App\Support\SimpleInterest::previewLateTotals($selected?->amountCents() ?? 0, $rate);
    }

    public function startEditCharges(): void
    {
        $row = Receivable::findOrFail($this->selectedId);
        $this->resetValidation();
        $this->charges = [
            'interest_rate_am' => rtrim(rtrim(number_format((float) $row->interest_rate_am, 4, '.', ''), '0'), '.') ?: '0',
            'interest_type' => $row->interestType(),
            'penalty_pct' => rtrim(rtrim(number_format((float) $row->penalty_pct, 2, '.', ''), '0'), '.') ?: '0',
            'late_interest_rate_am' => rtrim(rtrim(number_format((float) $row->late_interest_rate_am, 4, '.', ''), '0'), '.') ?: '0',
            'late_interest_type' => $row->lateInterestType(),
            'interest_start_date' => $row->interestStart(),
        ];
        $this->editingCharges = true;
    }

    public function cancelEditCharges(): void
    {
        $this->editingCharges = false;
        $this->resetValidation();
    }

    public function saveCharges(ReceivableService $service): void
    {
        foreach (['interest_rate_am' => 4, 'penalty_pct' => 2, 'late_interest_rate_am' => 4] as $k => $d) {
            if (isset($this->charges[$k]) && $this->charges[$k] !== '' && $this->charges[$k] !== null) {
                $this->charges[$k] = \App\Support\Money::decimal($this->charges[$k], $d);
            }
        }
        $this->charges['interest_type'] = \App\Support\SimpleInterest::normalizeType($this->charges['interest_type'] ?? null);
        $this->charges['late_interest_type'] = \App\Support\SimpleInterest::normalizeType($this->charges['late_interest_type'] ?? null);
        try {
            $service->updateCharges(Receivable::findOrFail($this->selectedId), $this->charges);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addError('receivable', $e->getMessage());
            return;
        }
        $this->editingCharges = false;
        session()->flash('status', 'Encargos atualizados. O saldo em aberto foi recalculado. Baixas já registradas não foram refeitas.');
        $this->open($this->selectedId);
    }

    public function save(ReceivableService $service): void
    {
        $this->normalizeFormMoney();
        $this->form['interest_type'] = \App\Support\SimpleInterest::normalizeType($this->form['interest_type'] ?? null);
        $this->form['late_interest_type'] = \App\Support\SimpleInterest::normalizeType($this->form['late_interest_type'] ?? null);
        $data = $this->form;
        $data['interest_start_date'] = ($data['interest_start_date'] ?? '') ?: null;
        $data['first_due_date'] = ($data['first_due_date'] ?? '') ?: null;
        foreach (['bank_account_id', 'credit_card_id', 'transaction_id', 'writ_id'] as $field) {
            $data[$field] = ($data[$field] ?? null) ?: null;
        }
        try {
            $rows = $service->create($data + ['request_key' => $this->createKey]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->validator->errors()->messages() as $key => $messages) {
                $formKey = str_starts_with($key, 'form.') ? $key : 'form.'.$key;
                foreach ($messages as $message) {
                    $this->addError($formKey, $message);
                }
            }

            return;
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addError('receivable', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('receivable', 'Não foi possível salvar. Confira os campos e tente de novo. Se o erro continuar, avise o suporte.');

            return;
        }
        $this->creating = false;
        $this->transaction = '';
        session()->flash('status', 'Valores a receber registrados. A parte de terceiros foi separada da despesa própria.');
        $this->open($rows[0]->id);
    }

    private function normalizeFormMoney(): void
    {
        $this->form['amount'] = \App\Support\Money::normalize($this->form['amount'] ?? '');
        foreach ($this->form['people'] ?? [] as $index => $person) {
            $this->form['people'][$index]['amount'] = \App\Support\Money::normalize($person['amount'] ?? '');
        }
        foreach (['interest_rate_am' => 4, 'penalty_pct' => 2, 'late_interest_rate_am' => 4] as $field => $decimals) {
            if (isset($this->form[$field]) && $this->form[$field] !== '' && $this->form[$field] !== null) {
                $this->form[$field] = \App\Support\Money::decimal($this->form[$field], $decimals);
            }
        }
    }

    /** Prévia do que acontece com o dinheiro, sem mudar a regra de negócio. */
    private function buildPreview($accounts, $contacts, $cards, $sources): array
    {
        $amount = \App\Support\Money::normalize($this->form['amount'] ?? '');
        $total = $amount !== '' ? (int) round((float) $amount * 100) : 0;
        $people = [];
        $owed = 0;
        foreach ($this->form['people'] ?? [] as $person) {
            $part = \App\Support\Money::normalize($person['amount'] ?? '');
            $cents = $part !== '' ? (int) round((float) $part * 100) : 0;
            $name = $contacts->firstWhere('id', (int) ($person['contact_id'] ?? 0))?->name;
            $people[] = ['name' => $name ?: 'pessoa ainda não escolhida', 'cents' => $cents];
            $owed += $cents;
        }
        $origin = $this->form['origin'] ?? 'bank';
        if ($origin === 'card') {
            $card = $cards->firstWhere('id', (int) ($this->form['credit_card_id'] ?? 0));
            $originLine = $card
                ? 'A compra de R$ '.$this->formatMoney($total).' entra no cartão '.$card->name.'.'
                : 'A compra entra no cartão escolhido (ainda falta escolher o cartão).';
        } elseif ($origin === 'existing') {
            $originLine = 'Nenhuma nova saída: o lançamento já registrado é reclassificado; o dinheiro não sai de novo.';
        } else {
            $account = $accounts->firstWhere('id', (int) ($this->form['bank_account_id'] ?? 0));
            $originLine = $account
                ? 'O valor de R$ '.$this->formatMoney($total).' sai da conta '.$account->name.'.'
                : 'O valor sai da conta escolhida (ainda falta escolher a conta).';
        }

        return [
            'ready' => $total > 0 && $owed > 0 && $owed <= $total,
            'overflow' => $owed > $total && $total > 0,
            'origin' => $originLine,
            'people' => $people,
            'own' => $total - $owed,
            'total' => $total,
            'owed' => $owed,
        ];
    }

    private function formatMoney(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    public function open(int $id): void
    {
        $row = Receivable::with('installments.settlements')->findOrFail($id);
        $this->selectedId = $id;
        $this->paymentKey = (string) Str::uuid();
        $this->payment = ['kind' => 'cash', 'amount' => app(ReceivableService::class)->money($row->balanceCents()), 'date' => \App\Support\BusinessDate::today(),
            'bank_account_id' => null, 'transaction_id' => null, 'destination_id' => null, 'installment_id' => null, 'reason' => '', 'cash' => false];
        $this->dates = $row->installments->mapWithKeys(fn ($part) => [$part->id => $part->due_date?->toDateString() ?? ''])->all();
        $this->editingCharges = false;
        $this->resetValidation();
    }

    public function closeDetail(): void { $this->selectedId = null; $this->editingCharges = false; $this->resetValidation(); }

    public function updatedPaymentCash($value): void
    {
        if ($value) {
            $cash = BankAccount::active()->where('type', BankAccount::TYPE_CASH)->orderBy('id')->first();
            $this->payment['bank_account_id'] = $cash?->id;
        }
    }

    public function settle(ReceivableService $service): void
    {
        $row = Receivable::findOrFail($this->selectedId);
        $this->validate(['payment.kind' => 'required|in:cash,offset,loss', 'payment.amount' => 'required|string',
            'payment.date' => 'required|date_format:Y-m-d', 'payment.bank_account_id' => 'nullable|integer',
            'payment.transaction_id' => 'nullable|integer', 'payment.destination_id' => 'nullable|integer', 'payment.installment_id' => 'nullable|integer']);
        try {
            if ($this->payment['kind'] === 'loss') {
                $service->writeOff($row, $this->payment['amount'], $this->payment['date'], $this->paymentKey, $this->payment['reason'] ?? '');
            } elseif ($this->payment['kind'] === 'offset') {
                $service->offset($row, ReceiptDestination::findOrFail($this->payment['destination_id']), $this->payment['amount'], $this->paymentKey);
            } else {
                $service->receive($row, $this->payment['amount'], $this->payment['date'], (int) $this->payment['bank_account_id'], $this->paymentKey,
                    $this->payment['transaction_id'] ?: null, $this->payment['installment_id'] ?: null);
            }
        } catch (\DomainException|\InvalidArgumentException $e) { $this->addError('receivable', $e->getMessage()); return; }
        $this->open($row->id);
        session()->flash('status', 'Baixa registrada. O saldo a receber foi atualizado.');
    }

    public function undo(string $key, ReceivableService $service): void
    {
        try { $service->undo(Receivable::findOrFail($this->selectedId), $key); }
        catch (\DomainException $e) { $this->addError('receivable', $e->getMessage()); return; }
        $this->open($this->selectedId);
        session()->flash('status', 'Baixa estornada. O valor voltou ao saldo a receber.');
    }

    public function reschedule(ReceivableService $service): void
    {
        $service->reschedule(Receivable::findOrFail($this->selectedId), $this->dates);
        session()->flash('status', 'Vencimentos atualizados.');
    }

    public function detach(ReceivableService $service): void
    {
        try { $service->detach(Receivable::findOrFail($this->selectedId)); }
        catch (\DomainException $e) { $this->addError('receivable', $e->getMessage()); return; }
        $this->selectedId = null;
        session()->flash('status', 'Controle removido. Os lançamentos originais foram preservados e voltaram a ser despesas próprias.');
    }

    public function with(): array
    {
        $all = Receivable::with(['contact' => fn ($q) => $q->withTests(), 'installments.settlements', 'installments.transaction.invoice'])
            ->when($this->testes === '1', fn ($q) => $q->withTests())
            ->when($this->contact, fn ($q) => $q->where('contact_id', $this->contact))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', '%'.$this->search.'%')->orWhereHas('contact', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('id')->get();
        $live = $all->reject(fn ($row) => $row->is_test);
        $parts = $live->flatMap->installments;
        $today = \Illuminate\Support\Carbon::parse(\App\Support\BusinessDate::today());
        $summary = [
            'Total em aberto' => $live->sum(fn ($row) => $row->balanceCents()),
            'Vencidos' => $live->filter(fn ($row) => $row->statusLabel() === 'Vencido')->sum(fn ($row) => $row->balanceCents()),
            'A vencer no mês' => $parts->filter(fn ($part) => $part->remainingCents() > 0 && $part->due_date?->between($today, $today->copy()->endOfMonth()))->sum(fn ($part) => $part->remainingCents()),
            'Sem data definida' => $parts->whereNull('due_date')->sum(fn ($part) => $part->remainingCents()),
        ];
        $filtered = $all->filter(fn ($row) => match ($this->status) {
            'open' => $row->balanceCents() > 0, 'paid' => $row->balanceCents() === 0,
            'overdue' => $row->statusLabel() === 'Vencido', default => true,
        })->values();
        $records = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($this->getPage(), 20)->values(), $filtered->count(), 20, $this->getPage(), ['path' => request()->url()]);
        $selected = $this->selectedId ? Receivable::with(['contact', 'writ', 'installments.transaction.invoice', 'installments.settlements.destination.receipt', 'installments.settlements.transaction.bankAccount'])->findOrFail($this->selectedId) : null;
        $destinations = $selected ? ReceiptDestination::with('receipt')->where('kind', 'client')->where('contact_id', $selected->contact_id)->get()->filter(fn ($part) => $part->remainingCents() > 0) : collect();
        $contacts = Contact::active()->orderBy('name')->get();
        $accounts = BankAccount::active()->where('type', '!=', 'repasse')->orderBy('name')->get();
        $cards = CreditCard::where('status', true)->orderBy('name')->get();
        $sources = $this->creating && $this->form['origin'] === 'existing' ? Transaction::where('type', 'expense')->where('status', 'settled')->whereNull('source_type')->where('amount', '>', 0)->whereDoesntHave('destinationPayment')->when($this->sourceSearch, fn ($q) => $q->where('description', 'like', '%'.$this->sourceSearch.'%'))->latest('date')->limit(200)->get()->merge(Transaction::whereKey($this->form['transaction_id'])->get())->unique('id') : collect();
        return [
            'isCardOrigin' => $this->form['origin'] === 'card' || ($this->form['origin'] === 'existing' && Transaction::whereKey($this->form['transaction_id'])->whereNotNull('credit_card_invoice_id')->exists()),
            'interestPreview' => $this->creating ? $this->interestPreview() : null,
            'lateInterestPreview' => $this->creating ? $this->lateInterestPreview() : null,
            'chargesPreview' => $this->editingCharges ? $this->chargesPreview() : null,
            'chargesLatePreview' => $this->editingCharges ? $this->chargesLatePreview() : null,
            'records' => $records, 'summary' => $summary, 'selected' => $selected, 'destinations' => $destinations,
            'contacts' => $contacts,
            'accounts' => $accounts,
            'writOptions' => $this->creating ? Writ::with('assignors.contact:id,name')->get(['id', 'process_number', 'assignor_name'])->map(function ($writ) {
                $names = $writ->assignors->filter(fn ($assignor) => $assignor->role !== 'advogado')
                    ->map(fn ($assignor) => $assignor->contact?->name)->filter()->unique()->join(', ');
                $name = $names ?: ($writ->assignor_name ?: 'Cedente não informado');
                return ['id' => $writ->id, 'label' => $name.' — '.($writ->process_number ?: 'Processo não informado')];
            })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values() : collect(),
            'cards' => $cards,
            'sources' => $sources,
            'incoming' => $selected ? Transaction::where('type', 'income')->where('status', 'settled')->whereNull('source_type')->whereNotNull('bank_account_id')->whereDoesntHave('destinations')->whereDoesntHave('receivableSettlements')->latest('date')->limit(200)->get() : collect(),
            'preview' => $this->creating ? $this->buildPreview($accounts, $contacts, $cards, $sources) : null,
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-6">
    <x-banking.subnav active="receivables" />
    @if ($selected)
        <x-banking.back-button action="closeDetail" label="Voltar à lista" />
    @else
        <x-banking.back-button :fallback="route('banking.dashboard')" />
    @endif
    @if (session('status')) <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert> @endif
    @if (! $creating && $errors->any())
        <x-jr.alert variant="error"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><h2 class="text-xl font-bold text-mono-900">Valores a receber</h2><p class="mt-1 text-sm text-mono-600">Quando você paga por outra pessoa, o dinheiro sai da sua conta (ou entra no cartão) e a parte dela vira um valor a receber. O que sobrar fica como sua despesa.</p></div>
        <x-jr.button wire:click="startCreate">Registrar que paguei por alguém</x-jr.button>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $label => $value)
            <x-fx.card><div class="text-xs font-medium uppercase tracking-wide text-mono-600">{{ $label }}</div><div class="mt-2 text-2xl font-bold tabular-nums {{ $label === 'Vencidos' && $value ? 'text-down' : 'text-mono-900' }}">R$ {{ number_format($value / 100, 2, ',', '.') }}</div></x-fx.card>
        @endforeach
    </div>

    @if ($selected)
        <x-fx.card>
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-lg font-bold text-mono-900">{{ $selected->description }}</h3><a class="text-sm text-primary-500" href="{{ route('contacts.show', $selected->contact_id) }}">{{ $selected->contact->name }}</a>@php $bal = $selected->balanceAsOf(); @endphp
            <p class="mt-2 text-sm text-mono-600">{{ $selected->statusLabel() }} · Principal: R$ {{ number_format($bal['principal_open']/100, 2, ',', '.') }} · Juros/mora: R$ {{ number_format(($bal['interest_open']+$bal['penalty_open'])/100, 2, ',', '.') }} · Saldo: <strong class="text-mono-900">R$ {{ number_format($bal['balance']/100, 2, ',', '.') }}</strong></p>
            <p class="mt-1 text-xs text-mono-600" data-receivable-rates>Juros combinados {{ number_format((float) $selected->interest_rate_am, 2, ',', '.') }}% a.m. ({{ \App\Support\SimpleInterest::typeLabel($selected->interestType()) }}) · Multa {{ number_format((float) $selected->penalty_pct, 2, ',', '.') }}% · Mora {{ number_format((float) $selected->late_interest_rate_am, 2, ',', '.') }}% a.m. ({{ \App\Support\SimpleInterest::typeLabel($selected->lateInterestType()) }})</p></div><x-banking.back-button action="closeDetail" label="Voltar" /></div>
            @if ($selected->process_reference || $selected->writ_id)<p class="mt-3 text-sm text-mono-600">Processo: {{ $selected->process_reference ?: 'Requisitório #'.$selected->writ_id }}</p>@endif
            @if ($selected->notes)<p class="mt-3 text-sm text-mono-600">{{ $selected->notes }}</p>@endif
            <div class="mt-5 overflow-x-auto">
                <table class="fx-table w-full text-sm"><thead><tr><th>Parcela</th><th>Valor</th><th>Saldo</th><th>Vencimento combinado</th><th>Fatura do cartão</th></tr></thead><tbody>
                @foreach ($selected->installments as $part)
                    <tr wire:key="part-{{ $part->id }}"><td>{{ $part->number }}</td><td>R$ {{ number_format($part->amount, 2, ',', '.') }}</td><td>R$ {{ number_format($part->remainingCents() / 100, 2, ',', '.') }}</td><td>@if ($part->remainingCents())<input aria-label="Vencimento da parcela {{ $part->number }}" type="date" wire:model="dates.{{ $part->id }}" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500" />@else {{ $part->due_date?->format('d/m/Y') ?: 'Sem data' }} @endif</td><td>{{ $part->transaction?->invoice?->reference_month ?: '—' }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3"><x-jr.button variant="standard" wire:click="reschedule">Salvar vencimentos</x-jr.button><span class="text-xs text-mono-600">Deixe a data vazia quando ainda não houver previsão.</span></div>
            <div class="mt-6 space-y-4 border-t border-mono-100 pt-5" data-receivable-charges>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h4 class="font-semibold text-mono-900">Encargos</h4>
                    @if (! $editingCharges)
                        <x-jr.button variant="standard" wire:click="startEditCharges" data-edit-charges>Editar encargos</x-jr.button>
                    @endif
                </div>
                @if ($editingCharges)
                    <form wire:submit="saveCharges" class="space-y-4" data-charges-form>
                        @if ($selected->settlements->isNotEmpty())
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">As baixas já registradas não são refeitas. Só o saldo em aberto é recalculado com o novo tipo e as novas taxas.</p>
                        @endif
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-3">
                                <x-jr.input label="Juros combinados (% a.m.)" name="charges.interest_rate_am" x-decimal="4" wire:model.live.debounce.400ms="charges.interest_rate_am" />
                                <x-banking.interest-type model="charges.interest_type" id="charges-interest-type" :preview="$chargesPreview" />
                            </div>
                            <x-jr.input label="Início da contagem dos juros" name="charges.interest_start_date" type="date" wire:model.live="charges.interest_start_date" />
                            <x-jr.input label="Multa após vencimento (%)" name="charges.penalty_pct" x-decimal="2" wire:model.live.debounce.400ms="charges.penalty_pct" />
                            <div class="space-y-3">
                                <x-jr.input label="Mora após vencimento (% a.m.)" name="charges.late_interest_rate_am" x-decimal="4" wire:model.live.debounce.400ms="charges.late_interest_rate_am" />
                                <x-banking.interest-type model="charges.late_interest_type" id="charges-late-interest-type" legend="Tipo da mora" :preview="$chargesLatePreview" />
                            </div>
                        </div>
                        <div class="flex flex-wrap justify-end gap-3">
                            <x-jr.button variant="standard" type="button" wire:click="cancelEditCharges">Cancelar</x-jr.button>
                            <x-jr.button type="submit" wire:loading.attr="disabled" wire:target="saveCharges">Salvar encargos</x-jr.button>
                        </div>
                    </form>
                @endif
            </div>
            @if ($selected->remainingCents())
                <form wire:submit="settle" class="mt-6 space-y-4 border-t border-mono-100 pt-5">
                    <h4 class="font-semibold text-mono-900">Registrar recebimento ou compensação</h4>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label for="payment-kind" class="mb-2 block text-sm text-mono-600">Forma de baixa</label><select id="payment-kind" wire:model.live="payment.kind" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="cash">Devolução em conta / dinheiro</option><option value="offset">Descontar da parte do cliente em um processo</option><option value="loss">Reconhecer perda / valor não será devolvido</option></select></div>
                        <x-jr.input label="Valor recebido / compensado (R$)" name="payment.amount" x-money inputmode="numeric" wire:model="payment.amount" />
                        @if ($payment['kind'] === 'cash')
                            <x-jr.input label="Data do recebimento" name="payment.date" type="date" wire:model="payment.date" />
                            <label class="flex items-center gap-2 text-sm text-mono-600 md:col-span-2"><input type="checkbox" wire:model.live="payment.cash" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500" />Devolução em dinheiro (espécie) — credita a conta Dinheiro</label>
                            <div><x-banking.account-picker :accounts="$accounts" :selected-id="$payment['bank_account_id'] ?? null" model="payment.bank_account_id" id="payment-account" label="Conta de entrada" /></div>
                            <div><label for="payment-existing" class="mb-2 block text-sm text-mono-600">Entrada já registrada (opcional)</label><select id="payment-existing" wire:model="payment.transaction_id" wire:change="loadIncoming" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Criar nova entrada</option>@foreach ($incoming as $entry)<option value="{{ $entry->id }}">#{{ $entry->id }} · {{ $entry->date->format('d/m/Y') }} · {{ $entry->description }} · R$ {{ number_format($entry->amount, 2, ',', '.') }}</option>@endforeach</select><p class="mt-2 text-xs text-mono-600">Ao vincular, use a mesma data, conta e valor da entrada existente.</p></div>
                            <div><label for="payment-part" class="mb-2 block text-sm text-mono-600">Aplicar em</label><select id="payment-part" wire:model="payment.installment_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Parcelas em ordem, permitindo antecipação</option>@foreach ($selected->installments->filter(fn ($part) => $part->remainingCents() > 0) as $part)<option value="{{ $part->id }}">Parcela {{ $part->number }} · R$ {{ number_format($part->remainingCents() / 100, 2, ',', '.') }}</option>@endforeach</select></div>
                        @elseif ($payment['kind'] === 'loss')
                            <x-jr.input label="Data do reconhecimento da perda" name="payment.date" type="date" wire:model="payment.date" />
                            <x-jr.input label="Motivo da perda" name="payment.reason" wire:model="payment.reason" />
                            <p class="text-sm text-down md:col-span-2">Esta baixa transforma o valor em despesa própria na data informada, sem nova saída bancária.</p>
                        @else
                            <div class="md:col-span-2"><label for="payment-destination" class="mb-2 block text-sm text-mono-600">Parte do cliente disponível para desconto</label><select id="payment-destination" wire:model="payment.destination_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($destinations as $part)<option value="{{ $part->id }}">Recebimento #{{ $part->receipt_id }} · {{ $part->receipt->description }} · Disponível R$ {{ number_format($part->remainingCents() / 100, 2, ',', '.') }}</option>@endforeach</select><p class="mt-2 text-xs text-mono-600">Vincule o contato à parte do cliente na divisão do recebimento. A compensação usa a data do processo e reduz o repasse, sem criar uma entrada bancária.</p></div>
                        @endif
                    </div>
                    <div class="flex justify-end"><x-jr.button type="submit" wire:loading.attr="disabled" wire:target="settle">Confirmar baixa</x-jr.button></div>
                </form>
            @endif
            <div class="mt-6 space-y-3 border-t border-mono-100 pt-5">
                <h4 class="font-semibold text-mono-900">Histórico de baixas</h4>
                @forelse ($selected->installments->flatMap->settlements->groupBy('batch_key') as $key => $batch)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-mono-50 p-3 text-sm"><div><strong>R$ {{ number_format($batch->sum('amount'), 2, ',', '.') }}</strong> · {{ $batch->first()->date->format('d/m/Y') }}<p class="mt-1 text-xs text-mono-600">{{ $batch->first()->kind === 'offset' ? 'Compensação no recebimento #'.$batch->first()->destination?->receipt_id : ($batch->first()->kind === 'loss' ? 'Perda reconhecida · '.$batch->first()->notes : 'Devolução em '.$batch->first()->transaction?->bankAccount?->name) }} · Parcelas {{ $batch->map(fn ($row) => $row->receivableInstallment->number)->join(', ') }}</p></div><button type="button" class="text-sm text-error" wire:click="undo('{{ $key }}')" wire:confirm="Estornar esta baixa completa? O saldo voltará a ficar em aberto. Entradas criadas por esta baixa serão removidas; entradas existentes serão apenas desvinculadas.">Estornar baixa</button></div>
                @empty <p class="text-sm text-mono-600">Nenhum recebimento ou compensação registrado.</p> @endforelse
            </div>
            @if (! $selected->settlements()->exists())<div class="mt-5"><button type="button" class="text-sm text-error" wire:click="detach" wire:confirm="Remover este controle? Os lançamentos originais serão preservados e esta parte voltará a ser despesa própria.">Remover controle e desfazer classificação</button></div>@endif
        </x-fx.card>
    @endif

    <x-fx.card>
        <div class="mb-5 grid gap-4 md:grid-cols-3">
            <x-jr.input label="Buscar" name="search" placeholder="Pessoa ou descrição" wire:model.live.debounce.300ms="search" />
            <label class="flex items-center gap-2 text-xs text-mono-600"><input type="checkbox" wire:model.live="testes" true-value="1" false-value=""> Mostrar testes (fora dos totais)</label>
            <x-banking.contact-picker :contacts="$contacts" :selected-id="$contact" model="contact" id="filter-contact" placeholder="Todas" clear-value="" />
            <div><label for="filter-status" class="mb-2 block text-sm text-mono-600">Situação</label><select id="filter-status" wire:model.live="status" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="open">Em aberto</option><option value="overdue">Vencidos</option><option value="paid">Encerrados</option><option value="">Todos</option></select></div>
        </div>
        <div class="overflow-x-auto"><table class="fx-table w-full text-sm"><thead><tr><th>Pessoa / descrição</th><th>Valor original</th><th>Baixado</th><th>Saldo</th><th>Próximo vencimento</th><th>Situação</th><th></th></tr></thead><tbody>
        @forelse ($records as $row)
            @php
                $next = $row->installments->filter(fn ($part) => $part->remainingCents() > 0 && $part->due_date)->sortBy('due_date')->first();
            @endphp
            <tr wire:key="receivable-{{ $row->id }}"><td><div class="font-medium text-mono-900">{{ $row->contact->name }}</div><div class="mt-1 text-xs text-mono-600">{{ $row->description }}</div></td><td class="whitespace-nowrap">R$ {{ number_format($row->amountCents() / 100, 2, ',', '.') }}</td><td class="whitespace-nowrap">R$ {{ number_format(($row->amountCents() - $row->remainingCents()) / 100, 2, ',', '.') }}</td><td class="whitespace-nowrap font-semibold">R$ {{ number_format($row->balanceCents() / 100, 2, ',', '.') }}</td><td>{{ $next?->due_date->format('d/m/Y') ?: ($row->remainingCents() === 0 ? '—' : ($row->repayment_mode === 'process' ? 'No recebimento do processo' : 'Sem data definida')) }}</td><td><x-jr.badge :variant="$row->balanceCents() === 0 ? 'success' : ($row->statusLabel() === 'Vencido' ? 'error' : 'neutral')">{{ $row->statusLabel() }}</x-jr.badge></td><td><button type="button" class="font-semibold text-primary-500" wire:click="open({{ $row->id }})">Gerenciar</button></td></tr>
        @empty <tr><td colspan="7" class="py-8 text-center text-mono-600">Nenhum valor a receber encontrado.</td></tr> @endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </x-fx.card>

    @if ($creating)
        <div class="fixed inset-0 z-modal flex items-end justify-center overflow-y-auto sm:items-center sm:px-4 sm:py-6" x-data @keydown.escape.window="$wire.cancelCreate()">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelCreate" aria-label="Fechar modal"></button>

            <div role="dialog" aria-modal="true" aria-labelledby="receivable-form-title" x-trap.inert.noscroll="true" class="relative flex max-h-[100dvh] w-full max-w-5xl flex-col overflow-hidden rounded-t-2xl border border-mono-100 bg-mono-white shadow-elevated sm:max-h-[90vh] sm:rounded-2xl">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-4 sm:px-6">
                    <h3 id="receivable-form-title" class="flex items-center gap-3 text-base font-bold text-mono-900 sm:text-lg">
                        <span class="material-icons-outlined text-[24px] text-primary-500" aria-hidden="true">request_quote</span>
                        Paguei por alguém
                    </h3>
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancelCreate" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]" aria-hidden="true">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                        @if ($errors->any())
                            <div class="mb-5" role="alert">
                                <x-jr.alert variant="error" :dismissible="false"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert>
                            </div>
                        @endif
                        <div class="space-y-5">
                            <div class="rounded-lg border border-primary-100 bg-primary-50/70 p-4 text-sm text-mono-800">
                                @if ($form['origin'] === 'card')
                                    Você pagou no cartão por outra pessoa: a compra entra na fatura. A parte dela vira um valor a receber; o que sobrar fica como sua despesa.
                                @elseif ($form['origin'] === 'existing')
                                    Você já pagou e só vai classificar o lançamento: o dinheiro não sai de novo. A parte dela vira um valor a receber; o que sobrar continua como sua despesa.
                                @else
                                    Você pagou por outra pessoa: o valor sai da sua conta e a parte dela vira um valor a receber. O que sobrar fica como sua despesa.
                                @endif
                            </div>

                            <div class="space-y-3 rounded-lg bg-mono-50 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-semibold text-mono-900">Por quem você pagou?</h4><button type="button" wire:click="addPerson" class="text-sm font-semibold text-primary-500">Adicionar pessoa</button></div>
                                @foreach ($form['people'] as $index => $person)
                                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]" wire:key="person-{{ $index }}-{{ $createKey }}">
                                        <div>
                                            <x-banking.contact-picker :contacts="$contacts" :selected-id="$person['contact_id']" :model="'form.people.'.$index.'.contact_id'" :id="'person-'.$index" />
                                            @error('form.people.'.$index.'.contact_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>
                                        <x-jr.input label="Quanto essa pessoa deve devolver (R$)" :name="'form.people.'.$index.'.amount'" x-money inputmode="numeric" wire:model.live.debounce.300ms="form.people.{{ $index }}.amount" />
                                        @if (count($form['people']) > 1)<x-jr.button variant="standard" class="w-full sm:w-auto" wire:click="removePerson({{ $index }})">Remover</x-jr.button>@endif
                                    </div>
                                @endforeach
                                <p class="text-xs text-mono-600">Se ela deve devolver o valor inteiro, coloque o mesmo valor do pagamento. Se vocês dividiram a conta, coloque só a parte dela; o restante fica como sua despesa.</p>
                                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end"><div class="min-w-0 flex-1"><x-jr.input label="Nova pessoa (ainda não cadastrada)" name="newContactName" wire:model="newContactName" placeholder="Nome do amigo ou familiar" /></div><x-jr.button variant="standard" class="w-full sm:w-auto" wire:click="createPersonalContact" wire:loading.attr="disabled" wire:target="createPersonalContact">Cadastrar e selecionar</x-jr.button></div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-jr.input label="O que foi pago" name="form.description" wire:model="form.description" placeholder="Ex.: Multa, jantar, compra compartilhada" />
                                <x-jr.input label="Data do pagamento ou da compra" name="form.date" type="date" wire:model="form.date" />
                                <x-jr.input label="Valor total do pagamento (R$)" name="form.amount" x-money inputmode="numeric" wire:model.live.debounce.300ms="form.amount" :readonly="$form['origin'] === 'existing'" />
                                <x-jr.input label="Número de parcelas" name="form.installments" type="number" min="1" max="120" wire:model="form.installments" helper="No cartão, cria as parcelas na fatura. Na saída bancária, divide as devoluções." />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="origin" class="mb-2 block text-sm text-mono-600">Como você pagou?</label>
                                    <select id="origin" wire:model.live="form.origin" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                                        <option value="bank">Saí com dinheiro da minha conta</option>
                                        <option value="card">Paguei no cartão</option>
                                        <option value="existing">Já está lançado no extrato ou na fatura</option>
                                    </select>
                                    @error('form.origin') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                </div>
                                @if ($form['origin'] === 'existing')
                                    <div>
                                        <x-jr.input label="Buscar lançamento por descrição" name="sourceSearch" wire:model.live.debounce.300ms="sourceSearch" />
                                        <label for="source" class="mb-2 block text-sm text-mono-600">Lançamento existente</label>
                                        <select id="source" wire:model="form.transaction_id" wire:change="loadSource" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                                            <option value="">Selecione</option>
                                            @foreach ($sources as $source)
                                                <option value="{{ $source->id }}">#{{ $source->id }} · {{ $source->date->format('d/m/Y') }} · {{ $source->description }} · R$ {{ number_format($source->amount, 2, ',', '.') }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-2 text-xs text-mono-600">Para compras parceladas, todas as parcelas da compra serão vinculadas. Não será criada outra saída.</p>
                                        @error('form.transaction_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                @elseif ($form['origin'] === 'bank')
                                    <div>
                                        <x-banking.account-picker :accounts="$accounts" :selected-id="$form['bank_account_id'] ?? null" model="form.bank_account_id" id="new-account" label="Conta de onde o dinheiro sai" />
                                        @error('form.bank_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                @else
                                    <div>
                                        <label for="new-card" class="mb-2 block text-sm text-mono-600">Cartão utilizado</label>
                                        <select id="new-card" wire:model.live="form.credit_card_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                                            <option value="">Selecione</option>
                                            @foreach ($cards as $card)
                                                <option value="{{ $card->id }}">{{ $card->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('form.credit_card_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="repayment" class="mb-2 block text-sm text-mono-600">Quando a pessoa deve devolver?</label>
                                    <select id="repayment" wire:model.live="form.repayment_mode" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500">
                                        <option value="dated">Em uma data combinada / parcelas mensais</option>
                                        <option value="process">Quando o processo for recebido</option>
                                        <option value="undated">Ainda sem data definida</option>
                                    </select>
                                </div>
                                @if ($form['repayment_mode'] === 'dated' && $isCardOrigin)
                                    <label class="flex items-center gap-2 text-sm text-mono-600"><input type="checkbox" wire:model.live="form.use_invoice_dates" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500" />Usar as datas de vencimento das faturas</label>
                                @endif
                                @if ($form['repayment_mode'] === 'dated' && ! ($form['use_invoice_dates'] ?? false))
                                    <x-jr.input label="Primeiro vencimento da devolução" name="form.first_due_date" type="date" wire:model="form.first_due_date" />
                                @endif
                                <x-jr.input label="Referência do processo (opcional)" name="form.process_reference" wire:model="form.process_reference" x-process-number />
                                <x-jr.searchable-select :options="$writOptions" :selected-id="$form['writ_id'] ?? null" model="form.writ_id" id="receivable-writ"
                                    label="Requisitório vinculado (opcional)" placeholder="Sem vínculo" search-label="Pesquisar cedente ou processo"
                                    search-placeholder="Digite o nome do cedente ou o processo..." empty-message="Nenhum requisitório encontrado." />
                            </div>

                            @if ($preview)
                                <div class="rounded-lg border border-mono-200 bg-mono-50 p-4" role="status" aria-live="polite">
                                    <h4 class="font-semibold text-mono-900">Antes de salvar, confira o que vai acontecer</h4>
                                    <ul class="mt-2 space-y-1 text-sm text-mono-700">
                                        <li>{{ $preview['origin'] }}</li>
                                        @foreach ($preview['people'] as $row)
                                            <li>
                                                @if ($row['cents'] > 0)
                                                    R$ {{ number_format($row['cents'] / 100, 2, ',', '.') }} vira valor a receber de {{ $row['name'] }}.
                                                @else
                                                    Ainda falta informar quanto {{ $row['name'] }} deve devolver.
                                                @endif
                                            </li>
                                        @endforeach
                                        @if ($preview['overflow'])
                                            <li class="font-medium text-error">A soma das partes (R$ {{ number_format($preview['owed'] / 100, 2, ',', '.') }}) é maior que o pagamento (R$ {{ number_format($preview['total'] / 100, 2, ',', '.') }}). Ajuste os valores antes de salvar.</li>
                                        @elseif ($preview['own'] > 0)
                                            <li>R$ {{ number_format($preview['own'] / 100, 2, ',', '.') }} fica como sua despesa própria.</li>
                                        @elseif ($preview['ready'])
                                            <li>O valor inteiro vira valor a receber (adiantamento integral). Não fica despesa própria.</li>
                                        @endif
                                    </ul>
                                </div>
                            @endif

                            <div class="rounded-lg border border-mono-100 bg-mono-50 p-4" data-receivable-create-charges>
                                <h4 class="font-semibold text-mono-900">Encargos opcionais (padrão: nenhum)</h4>
                                <p class="mt-1 text-xs text-mono-600">Só preencha se vocês combinaram juros ou multa. Vazio ou zero = sem encargos. Simples = P × (1 + i × n). Compostos = P × (1 + i)^n. Taxa em % a.m., n = dias/30. Multa uma vez + mora após o vencimento. Baixas abatem multa → juros → principal. Juros só viram receita quando recebidos. A prévia usa a primeira pessoa, o vencimento (ou 30 dias) e, na mora, 30 dias de atraso.</p>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div class="space-y-3">
                                        <x-jr.input label="Juros combinados (% a.m.)" name="form.interest_rate_am" x-decimal="4" wire:model.live.debounce.400ms="form.interest_rate_am" />
                                        <x-banking.interest-type model="form.interest_type" id="receivable-interest-type" :preview="$interestPreview" />
                                    </div>
                                    <x-jr.input label="Início da contagem dos juros" name="form.interest_start_date" type="date" wire:model.live="form.interest_start_date" helper="Se deixar vazio, vale a data do pagamento" />
                                    <x-jr.input label="Multa após vencimento (%)" name="form.penalty_pct" x-decimal="2" wire:model="form.penalty_pct" />
                                    <div class="space-y-3">
                                        <x-jr.input label="Mora após vencimento (% a.m.)" name="form.late_interest_rate_am" x-decimal="4" wire:model.live.debounce.400ms="form.late_interest_rate_am" />
                                        <x-banking.interest-type model="form.late_interest_type" id="receivable-late-interest-type" legend="Tipo da mora" :preview="$lateInterestPreview" />
                                    </div>
                                </div>
                            </div>

                            <x-jr.input label="Observações" name="form.notes" wire:model="form.notes" />
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col-reverse gap-3 border-t border-mono-100 bg-mono-50 px-4 py-4 sm:flex-row sm:justify-end sm:px-6">
                        <x-jr.button variant="standard" class="w-full sm:w-auto" wire:click="cancelCreate">Cancelar</x-jr.button>
                        <x-jr.button type="submit" class="w-full sm:w-auto" wire:loading.attr="disabled" wire:target="save">Salvar</x-jr.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>

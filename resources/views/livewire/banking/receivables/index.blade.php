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

    public function mount(): void
    {
        $this->resetCreate();
        if ($this->transaction !== '') { $this->startCreate((int) $this->transaction); }
    }

    public function updatedContact(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }

    private function resetCreate(): void
    {
        $this->createKey = (string) Str::uuid();
        $this->form = ['origin' => 'bank', 'date' => today()->toDateString(), 'description' => '', 'amount' => '',
            'bank_account_id' => null, 'credit_card_id' => null, 'transaction_id' => null, 'installments' => 1,
            'repayment_mode' => 'dated', 'first_due_date' => today()->addMonthNoOverflow()->toDateString(),
            'use_invoice_dates' => false, 'writ_id' => null, 'process_reference' => '', 'notes' => '', 'people' => [['contact_id' => $this->contact ?: null, 'amount' => '']]];
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
        $contact = Contact::create(['name' => trim($this->newContactName), 'type' => 'pessoal', 'status' => true]);
        $last = count($this->form['people']) - 1;
        $this->form['people'][$last]['contact_id'] = $contact->id;
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
        $this->form['date'] = min($source->date->toDateString(), today()->toDateString());
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

    public function save(ReceivableService $service): void
    {
        try { $rows = $service->create($this->form + ['request_key' => $this->createKey]); }
        catch (\DomainException|\InvalidArgumentException $e) { $this->addError('receivable', $e->getMessage()); return; }
        $this->creating = false;
        $this->transaction = '';
        session()->flash('status', 'Valores a receber registrados. A parte de terceiros foi separada da despesa própria.');
        $this->open($rows[0]->id);
    }

    public function open(int $id): void
    {
        $row = Receivable::with('installments.settlements')->findOrFail($id);
        $this->selectedId = $id;
        $this->paymentKey = (string) Str::uuid();
        $this->payment = ['kind' => 'cash', 'amount' => app(ReceivableService::class)->money($row->remainingCents()), 'date' => today()->toDateString(),
            'bank_account_id' => null, 'transaction_id' => null, 'destination_id' => null, 'installment_id' => null, 'reason' => ''];
        $this->dates = $row->installments->mapWithKeys(fn ($part) => [$part->id => $part->due_date?->toDateString() ?? ''])->all();
        $this->resetValidation();
    }

    public function closeDetail(): void { $this->selectedId = null; $this->resetValidation(); }

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
        $all = Receivable::with(['contact', 'installments.settlements', 'installments.transaction.invoice'])
            ->when($this->contact, fn ($q) => $q->where('contact_id', $this->contact))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', '%'.$this->search.'%')->orWhereHas('contact', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('id')->get();
        $parts = $all->flatMap->installments;
        $summary = [
            'Total em aberto' => $parts->sum(fn ($part) => $part->remainingCents()),
            'Vencidos' => $parts->filter(fn ($part) => $part->due_date?->lt(today()))->sum(fn ($part) => $part->remainingCents()),
            'A vencer no mês' => $parts->filter(fn ($part) => $part->due_date?->between(today(), today()->endOfMonth()))->sum(fn ($part) => $part->remainingCents()),
            'Sem data definida' => $parts->whereNull('due_date')->sum(fn ($part) => $part->remainingCents()),
        ];
        $filtered = $all->filter(fn ($row) => match ($this->status) {
            'open' => $row->remainingCents() > 0, 'paid' => $row->remainingCents() === 0,
            'overdue' => $row->statusLabel() === 'Vencido', default => true,
        })->values();
        $records = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($this->getPage(), 20)->values(), $filtered->count(), 20, $this->getPage(), ['path' => request()->url()]);
        $selected = $this->selectedId ? Receivable::with(['contact', 'writ', 'installments.transaction.invoice', 'installments.settlements.destination.receipt', 'installments.settlements.transaction.bankAccount'])->findOrFail($this->selectedId) : null;
        $destinations = $selected ? ReceiptDestination::with('receipt')->where('kind', 'client')->where('contact_id', $selected->contact_id)->get()->filter(fn ($part) => $part->remainingCents() > 0) : collect();
        return [
            'isCardOrigin' => $this->form['origin'] === 'card' || ($this->form['origin'] === 'existing' && Transaction::whereKey($this->form['transaction_id'])->whereNotNull('credit_card_invoice_id')->exists()),
            'records' => $records, 'summary' => $summary, 'selected' => $selected, 'destinations' => $destinations,
            'contacts' => Contact::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->where('type', '!=', 'repasse')->orderBy('name')->get(),
            'writs' => $this->creating ? Writ::orderByDesc('id')->get(['id', 'process_number', 'assignor_name']) : collect(),
            'cards' => CreditCard::where('status', true)->orderBy('name')->get(),
            'sources' => $this->creating && $this->form['origin'] === 'existing' ? Transaction::where('type', 'expense')->where('status', 'settled')->whereNull('source_type')->where('amount', '>', 0)->whereDoesntHave('destinationPayment')->when($this->sourceSearch, fn ($q) => $q->where('description', 'like', '%'.$this->sourceSearch.'%'))->latest('date')->limit(200)->get()->merge(Transaction::whereKey($this->form['transaction_id'])->get())->unique('id') : collect(),
            'incoming' => $selected ? Transaction::where('type', 'income')->where('status', 'settled')->whereNull('source_type')->whereNotNull('bank_account_id')->whereDoesntHave('destinations')->whereDoesntHave('receivableSettlements')->latest('date')->limit(200)->get() : collect(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-6">
    <x-banking.subnav active="receivables" />
    @if (session('status')) <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert> @endif
    @if ($errors->any())
        <x-jr.alert variant="error"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-jr.alert>
    @endif
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><h2 class="text-xl font-bold text-mono-900">Valores a receber</h2><p class="mt-1 text-sm text-mono-600">Adiantamentos e pagamentos feitos por outras pessoas. Acompanhe devoluções e compensações.</p></div>
        <x-jr.button wire:click="startCreate">Novo valor a receber</x-jr.button>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $label => $value)
            <x-fx.card><div class="text-xs font-medium uppercase tracking-wide text-mono-600">{{ $label }}</div><div class="mt-2 text-2xl font-bold tabular-nums {{ $label === 'Vencidos' && $value ? 'text-down' : 'text-mono-900' }}">R$ {{ number_format($value / 100, 2, ',', '.') }}</div></x-fx.card>
        @endforeach
    </div>

    @if ($creating)
        <x-fx.card>
            <form wire:submit="save" class="space-y-5">
                <h3 class="text-lg font-bold text-mono-900">Novo valor a receber</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label for="origin" class="mb-2 block text-sm text-mono-600">Origem do pagamento</label><select id="origin" wire:model.live="form.origin" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="bank">Registrar nova saída bancária</option><option value="card">Registrar nova compra no cartão</option><option value="existing">Vincular lançamento existente</option></select></div>
                    @if ($form['origin'] === 'existing')
                        <div><x-jr.input label="Buscar lançamento por descrição" name="sourceSearch" wire:model.live.debounce.300ms="sourceSearch" /><label for="source" class="mb-2 block text-sm text-mono-600">Lançamento existente</label><select id="source" wire:model="form.transaction_id" wire:change="loadSource" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($sources as $source)<option value="{{ $source->id }}">#{{ $source->id }} · {{ $source->date->format('d/m/Y') }} · {{ $source->description }} · R$ {{ number_format($source->amount, 2, ',', '.') }}</option>@endforeach</select><p class="mt-2 text-xs text-mono-600">Para compras parceladas, todas as parcelas da compra serão vinculadas. Não será criada outra saída.</p></div>
                    @elseif ($form['origin'] === 'bank')
                        <div><label for="new-account" class="mb-2 block text-sm text-mono-600">Conta de saída</label><select id="new-account" wire:model="form.bank_account_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                    @else
                        <div><label for="new-card" class="mb-2 block text-sm text-mono-600">Cartão utilizado</label><select id="new-card" wire:model="form.credit_card_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($cards as $card)<option value="{{ $card->id }}">{{ $card->name }}</option>@endforeach</select></div>
                    @endif
                    <x-jr.input label="Descrição" name="form.description" wire:model="form.description" />
                    <x-jr.input label="Data do pagamento ou da compra" name="form.date" type="date" wire:model="form.date" />
                    <x-jr.input label="Valor total do pagamento (R$)" name="form.amount" x-money inputmode="numeric" wire:model="form.amount" :readonly="$form['origin'] === 'existing'" />
                    <x-jr.input label="Número de parcelas" name="form.installments" type="number" min="1" max="120" wire:model="form.installments" helper="No cartão, cria as parcelas na fatura. Na saída bancária, divide as devoluções." />
                </div>
                <div class="space-y-3 rounded-lg bg-mono-50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-semibold text-mono-900">Quem deve devolver?</h4><button type="button" wire:click="addPerson" class="text-sm font-semibold text-primary-500">Adicionar pessoa</button></div>
                    @foreach ($form['people'] as $index => $person)
                        <div class="grid items-end gap-3 md:grid-cols-[1fr_1fr_auto]" wire:key="person-{{ $index }}-{{ $createKey }}">
                            <div><label for="person-{{ $index }}" class="mb-2 block text-sm text-mono-600">Pessoa</label><select id="person-{{ $index }}" wire:model="form.people.{{ $index }}.contact_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                            <x-jr.input label="Parte a devolver (R$)" :name="'form.people.'.$index.'.amount'" x-money inputmode="numeric" wire:model="form.people.{{ $index }}.amount" />
                            @if (count($form['people']) > 1)<x-jr.button variant="standard" wire:click="removePerson({{ $index }})">Remover</x-jr.button>@endif
                        </div>
                    @endforeach
                    <p class="text-xs text-mono-600">O restante do pagamento será a despesa própria do Dr. Cássio. Para um adiantamento integral, informe o valor total na parte da pessoa.</p>
                    <div class="flex flex-wrap items-end gap-3"><x-jr.input label="Novo contato pessoal" name="newContactName" wire:model="newContactName" placeholder="Nome do amigo ou familiar" /><x-jr.button variant="standard" wire:click="createPersonalContact">Cadastrar e selecionar</x-jr.button></div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label for="repayment" class="mb-2 block text-sm text-mono-600">Quando deve devolver?</label><select id="repayment" wire:model.live="form.repayment_mode" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="dated">Em data combinada / parcelas mensais</option><option value="process">No recebimento do processo</option><option value="undated">Sem data definida</option></select></div>
                    @if ($form['repayment_mode'] === 'dated' && $isCardOrigin)<label class="flex items-center gap-2 text-sm text-mono-600"><input type="checkbox" wire:model.live="form.use_invoice_dates" class="rounded border-mono-200 text-primary-500 focus:ring-primary-500" />Devoluções nos vencimentos das faturas</label>@endif
                    @if ($form['repayment_mode'] === 'dated' && ! ($form['use_invoice_dates'] ?? false))<x-jr.input label="Primeiro vencimento" name="form.first_due_date" type="date" wire:model="form.first_due_date" />@endif
                    <x-jr.input label="Referência do processo (opcional)" name="form.process_reference" wire:model="form.process_reference" />
                    <div><label for="receivable-writ" class="mb-2 block text-sm text-mono-600">Requisitório vinculado (opcional)</label><select id="receivable-writ" wire:model="form.writ_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900"><option value="">Sem vínculo</option>@foreach ($writs as $writ)<option value="{{ $writ->id }}">{{ $writ->process_number ?: $writ->assignor_name }}</option>@endforeach</select></div>
                    <x-jr.input label="Observações" name="form.notes" wire:model="form.notes" />
                </div>
                <div class="flex justify-end gap-3"><x-jr.button variant="standard" wire:click="cancelCreate">Cancelar</x-jr.button><x-jr.button type="submit" wire:loading.attr="disabled" wire:target="save">Salvar</x-jr.button></div>
            </form>
        </x-fx.card>
    @endif

    @if ($selected)
        <x-fx.card>
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="text-lg font-bold text-mono-900">{{ $selected->description }}</h3><a class="text-sm text-primary-500" href="{{ route('contacts.show', $selected->contact_id) }}">{{ $selected->contact->name }}</a><p class="mt-2 text-sm text-mono-600">{{ $selected->statusLabel() }} · Saldo: <strong class="text-mono-900">R$ {{ number_format($selected->remainingCents() / 100, 2, ',', '.') }}</strong></p></div><x-jr.button variant="standard" wire:click="closeDetail">Fechar detalhes</x-jr.button></div>
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
            @if ($selected->remainingCents())
                <form wire:submit="settle" class="mt-6 space-y-4 border-t border-mono-100 pt-5">
                    <h4 class="font-semibold text-mono-900">Registrar recebimento ou compensação</h4>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label for="payment-kind" class="mb-2 block text-sm text-mono-600">Forma de baixa</label><select id="payment-kind" wire:model.live="payment.kind" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="cash">Devolução em conta bancária</option><option value="offset">Descontar da parte do cliente em um processo</option><option value="loss">Reconhecer perda / valor não será devolvido</option></select></div>
                        <x-jr.input label="Valor recebido / compensado (R$)" name="payment.amount" x-money inputmode="numeric" wire:model="payment.amount" />
                        @if ($payment['kind'] === 'cash')
                            <x-jr.input label="Data do recebimento" name="payment.date" type="date" wire:model="payment.date" />
                            <div><label for="payment-account" class="mb-2 block text-sm text-mono-600">Conta de entrada</label><select id="payment-account" wire:model="payment.bank_account_id" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Selecione</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
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
            <div><label for="filter-contact" class="mb-2 block text-sm text-mono-600">Pessoa</label><select id="filter-contact" wire:model.live="contact" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="">Todas</option>@foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            <div><label for="filter-status" class="mb-2 block text-sm text-mono-600">Situação</label><select id="filter-status" wire:model.live="status" class="h-12 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-primary-500 w-full"><option value="open">Em aberto</option><option value="overdue">Vencidos</option><option value="paid">Encerrados</option><option value="">Todos</option></select></div>
        </div>
        <div class="overflow-x-auto"><table class="fx-table w-full text-sm"><thead><tr><th>Pessoa / descrição</th><th>Valor original</th><th>Baixado</th><th>Saldo</th><th>Próximo vencimento</th><th>Situação</th><th></th></tr></thead><tbody>
        @forelse ($records as $row)
            @php
                $next = $row->installments->filter(fn ($part) => $part->remainingCents() > 0 && $part->due_date)->sortBy('due_date')->first();
            @endphp
            <tr wire:key="receivable-{{ $row->id }}"><td><div class="font-medium text-mono-900">{{ $row->contact->name }}</div><div class="mt-1 text-xs text-mono-600">{{ $row->description }}</div></td><td class="whitespace-nowrap">R$ {{ number_format($row->amountCents() / 100, 2, ',', '.') }}</td><td class="whitespace-nowrap">R$ {{ number_format(($row->amountCents() - $row->remainingCents()) / 100, 2, ',', '.') }}</td><td class="whitespace-nowrap font-semibold">R$ {{ number_format($row->remainingCents() / 100, 2, ',', '.') }}</td><td>{{ $next?->due_date->format('d/m/Y') ?: ($row->remainingCents() === 0 ? '—' : ($row->repayment_mode === 'process' ? 'No recebimento do processo' : 'Sem data definida')) }}</td><td><x-jr.badge :variant="$row->remainingCents() === 0 ? 'success' : ($row->statusLabel() === 'Vencido' ? 'error' : 'neutral')">{{ $row->statusLabel() }}</x-jr.badge></td><td><button type="button" class="font-semibold text-primary-500" wire:click="open({{ $row->id }})">Gerenciar</button></td></tr>
        @empty <tr><td colspan="7" class="py-8 text-center text-mono-600">Nenhum valor a receber encontrado.</td></tr> @endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $records->links() }}</div>
    </x-fx.card>
</div>

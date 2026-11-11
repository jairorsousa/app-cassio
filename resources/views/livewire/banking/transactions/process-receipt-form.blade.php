<?php

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use App\Domains\Writs\Models\Writ;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $receiptId;
    public array $form = [
        'client_contact_id' => null, 'receivables' => [], 'client_name' => '', 'process_ref' => '', 'category_id' => null,
        'fees' => '0.00', 'fees_mode' => 'amount', 'office_amount' => '0.00',
        'commission_mode' => 'amount', 'expenses' => [], 'brokers' => [],
        'includes_writ' => false, 'writ_id' => null, 'writ_amount' => '',
    ];

    public function mount(int $receiptId): void
    {
        app(\App\Domains\Brokers\Services\BrokerProfileService::class)->synchronizeDirectory();
        $this->receiptId = $receiptId;
        $receipt = Transaction::findOrFail($receiptId);
        app(\App\Domains\Banking\Services\ReceiptDestinationService::class)->eligible($receipt);
        $parts = $receipt->destinations()->with('commission')->orderBy('id')->get();
        $process = $receipt->processReceipt()->with('brokerShares')->first();
        $brokers = $process && $process->brokerShares->isNotEmpty()
            ? $process->brokerShares->map(fn ($share) => ['key' => (string) Str::uuid(), 'broker_id' => $share->broker_id, 'base' => $share->base, 'mode' => $share->mode, 'value' => (string) $share->rate_or_amount, 'auto' => false,
                'advances' => $share->commission_id ? \App\Domains\Brokers\Models\BrokerCommissionSettlement::where('commission_id', $share->commission_id)->get()->groupBy('advance_id')->map(fn ($rows) => number_format((float) $rows->sum('amount_offset'), 2, '.', ''))->all() : []])->all()
            : $parts->where('kind', 'broker')->filter(fn ($part) => $part->commission)->map(fn ($part) => ['key' => (string) Str::uuid(), 'broker_id' => $part->commission->broker_id, 'base' => 'fees', 'mode' => 'fixed', 'value' => (string) $part->amount, 'auto' => false, 'advances' => []])->values()->all();
        $this->form = array_replace($this->form, [
            'brokers' => $brokers,
            'client_contact_id' => $parts->firstWhere('kind', 'client')?->contact_id ?? $receipt->link_contact_id,
            'client_name' => $parts->firstWhere('kind', 'client')?->beneficiary ?? '',
            'process_ref' => $process?->process_ref ?? (string) $receipt->link_process,
            'category_id' => $process?->category_id,
            'fees' => $process ? (string) $process->fees_amount : number_format($parts->whereIn('kind', ['own', 'broker', 'office'])->sum('amount'), 2, '.', ''),
            'office_amount' => number_format($parts->where('kind', 'office')->sum('amount'), 2, '.', ''),
            'includes_writ' => (bool) $process?->writ_id,
            'writ_id' => $process?->writ_id,
            'writ_amount' => $process?->writ_amount ? (string) $process->writ_amount : '',
        ]);
    }

    public function updatedForm($value, ?string $key = null): void
    {
        if ($key === 'client_contact_id') {
            $this->form['receivables'] = [];
            if ($this->form['client_contact_id'] && $this->form['client_name'] === '') { $this->form['client_name'] = \App\Domains\Contacts\Models\Contact::find($this->form['client_contact_id'])?->name ?? ''; }
        }
        if ($key && preg_match('/^brokers\.(\d+)\.broker_id$/', $key, $m)) { $this->form['brokers'][$m[1]]['advances'] = []; $this->form['brokers'][$m[1]]['auto'] = false; $this->form['expenses'] = []; }
        if ($key && preg_match('/^brokers\.(\d+)\.auto$/', $key, $m) && empty($this->form['brokers'][$m[1]]['auto'])) { $this->form['brokers'][$m[1]]['advances'] = []; }
        if ($key === 'includes_writ' && ! $this->form['includes_writ']) { $this->form['writ_id'] = null; $this->form['writ_amount'] = ''; }
        if ($key === 'writ_id' && $this->form['writ_id']) {
            $writ = Writ::with('defaultBrokers')->find($this->form['writ_id']);
            if ($writ && $this->form['writ_amount'] === '' && $writ->estimated_receipt_amount) {
                $this->form['writ_amount'] = number_format((float) $writ->estimated_receipt_amount, 2, '.', '');
            }
            if ($writ && empty(array_filter($this->form['brokers'] ?? [], fn ($row) => ! empty($row['broker_id'])))) {
                $defaults = $writ->defaultBrokers;
                if ($defaults->isNotEmpty()) {
                    $this->form['brokers'] = $defaults->map(fn ($row) => [
                        'key' => (string) \Illuminate\Support\Str::uuid(),
                        'broker_id' => $row->broker_id,
                        'base' => $row->base ?: 'fees',
                        'mode' => $row->mode === 'fixed' ? 'fixed' : 'percent',
                        'value' => (string) $row->rate_or_amount,
                        'advances' => [],
                    ])->all();
                }
            }
        }
        if (array_key_exists('broker_id', $this->form)) {
            // Formato antigo (um corretor), mantido para integrações e testes existentes.
            if ($key === 'broker_id') { $this->form['expenses'] = []; $this->form['advances'] = []; }
            if (empty($this->form['broker_id'])) { $this->form['broker_amount'] = '0.00'; $this->form['expenses'] = []; $this->form['advances'] = []; }
        }
        $this->resetValidation();
    }

    public function addBroker(): void
    {
        if (count($this->form['brokers']) < 10) {
            $this->form['brokers'][] = ['key' => (string) Str::uuid(), 'broker_id' => null, 'base' => 'fees', 'mode' => 'percent', 'value' => '', 'auto' => false, 'advances' => []];
        }
    }

    public function removeBroker(int $index): void
    {
        if (isset($this->form['brokers'][$index])) {
            array_splice($this->form['brokers'], $index, 1);
            $this->form['expenses'] = [];
        }
    }

    public function setMode(string $field, string $mode, ProcessReceiptService $service): void
    {
        if (! in_array($field, ['fees_mode', 'commission_mode'], true) || ! in_array($mode, ['amount', 'percent'], true)) { return; }
        if ($this->form[$field] === $mode) { return; }
        try {
            $values = $service->calculate(Transaction::findOrFail($this->receiptId), $this->payload());
            $base = $field === 'fees_mode' ? $values['total'] : $values['fees'];
            $legacy = array_key_exists('broker_id', $this->form);
            foreach ($field === 'fees_mode' ? ['fees' => 'fees'] : ($legacy ? ['broker_amount' => 'broker', 'office_amount' => 'office'] : ['office_amount' => 'office']) as $input => $result) {
                $this->form[$input] = $mode === 'amount' ? $service->money($values[$result]) : number_format($base ? $values[$result] * 100 / $base : 0, 2, '.', '');
            }
            $this->form[$field] = $mode;
        } catch (\DomainException $e) { $this->addError('division', $e->getMessage()); }
    }

    /** Dados enviados ao serviço: corretores sem linha vazia e valor zero quando não preenchido. */
    private function payload(): array
    {
        $data = $this->form;
        $data['includes_writ'] = (bool) ($data['includes_writ'] ?? false);
        if (array_key_exists('broker_id', $data)) {
            unset($data['brokers']);

            return $data;
        }
        $data['brokers'] = array_values(array_map(fn ($row) => array_replace($row, ['value' => ($row['value'] ?? '') === '' ? '0' : (string) $row['value']]), array_filter($data['brokers'], fn ($row) => ! empty($row['broker_id']))));
        $data['advances'] = [];

        return $data;
    }

    public function save(ProcessReceiptService $service): void
    {
        try { $service->save(Transaction::findOrFail($this->receiptId), $this->payload()); }
        catch (\DomainException $e) { $this->addError('division', $e->getMessage()); return; }
        $this->dispatch('process-receipt-saved', receiptId: $this->receiptId);
        $this->dispatch('receipt-destination-updated');
    }

    public function with(ProcessReceiptService $service): array
    {
        $receipt = Transaction::findOrFail($this->receiptId);
        $preview = null;
        $previewError = null;
        try { $preview = $service->calculate($receipt, $this->payload()); }
        catch (\DomainException $e) { $previewError = $e->getMessage(); }
        $this->form += ['brokers' => [], 'includes_writ' => false, 'writ_id' => null, 'writ_amount' => '', 'process_ref' => '', 'expenses' => []];
        $brokerIds = collect($this->form['brokers'])->pluck('broker_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $expenses = $brokerIds->isEmpty() ? collect() : BrokerProcessExpense::with('broker')->whereIn('broker_id', $brokerIds)->orderBy('date')->get()->filter(fn ($expense) => $expense->remainingCents() > 0);
        // Adiantamentos em aberto por corretor (mais antigo primeiro). Na edição, o que este recebimento já compensou conta como disponível.
        $advances = $brokerIds->mapWithKeys(fn ($id) => [$id => $service->openAdvances($id, $receipt)])->filter(fn ($rows) => $rows->isNotEmpty());
        // Compensação automática marcada: calcula o abatimento do mais antigo para o mais novo até o valor da comissão.
        $autoAllocations = [];
        $position = 0;
        foreach ($this->form['brokers'] as $index => $row) {
            if (empty($row['broker_id'])) { continue; }
            if (! empty($row['auto'])) {
                $autoAllocations[$index] = $service->autoAllocation((int) $row['broker_id'], (int) ($preview['brokers'][$position] ?? 0), $receipt);
            }
            $position++;
        }
        $recovered = collect($this->form['expenses'])->sum(fn ($amount) => is_numeric($amount) ? (int) round((float) $amount * 100) : 0);
        $offset = collect($this->form['brokers'])->map(fn ($row, $index) => isset($autoAllocations[$index]) ? array_sum($autoAllocations[$index]) : collect($row['advances'] ?? [])->sum(fn ($amount) => is_numeric($amount) ? (int) round((float) $amount * 100) : 0))->sum();
        $writCents = $this->form['includes_writ'] && is_numeric($this->form['writ_amount']) ? (int) round((float) $this->form['writ_amount'] * 100) : 0;
        return compact('preview', 'previewError', 'expenses', 'advances', 'autoAllocations', 'recovered', 'offset', 'writCents') + [
            'contacts' => \App\Domains\Contacts\Models\Contact::active()->orderBy('name')->get(),
            'clientAdvances' => empty($this->form['client_contact_id']) ? collect() : \App\Domains\Banking\Models\Receivable::with('installments.settlements')->where('contact_id', $this->form['client_contact_id'])->get()->filter(fn ($row) => $row->remainingCents() > 0),
            'clientOffset' => collect($this->form['receivables'] ?? [])->sum(fn ($amount) => is_numeric($amount) ? (float) $amount : 0),
            'brokers' => Broker::selectable()->orderBy('name')->get(),
            'writs' => Writ::where('paid_amount', '>', 0)->where(fn ($q) => $q->whereIn('stage', ['paid', 'petitioning', 'awaiting_receipt'])->orWhere('id', $this->form['writ_id'] ?: 0))->with('assignors.contact:id,name')->orderBy('assignor_name')->get(),
            'categories' => Category::active()->where('type', 'income')->orderBy('name')->get(),
        ];
    }
}; ?>

<form wire:submit="save" class="space-y-5">
    <p class="text-sm text-mono-600">Uma única entrada no banco, com o valor bruto. Separe a parte do cliente (ou do requisitório que você comprou) e os honorários; os honorários são divididos entre escritório, um ou mais corretores e a sua receita.</p>
    <div class="grid gap-4 md:grid-cols-2">
        <div><label for="process-client-contact" class="mb-2 block text-sm text-mono-600">Contato do cliente</label><select id="process-client-contact" wire:model.live="form.client_contact_id" class="h-12 w-full rounded-pill border-mono-200 bg-mono-white text-sm text-mono-900"><option value="">Não vinculado</option>@foreach ($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</select></div>
        <x-jr.input label="Cliente (nome no repasse) *" name="form.client_name" wire:model="form.client_name" maxlength="200" required />
        <x-jr.input label="Processo (número)" name="form.process_ref" wire:model="form.process_ref" x-process-number maxlength="120" placeholder="0000000-00.0000.0.00.0000" />
    </div>
    <section class="space-y-3 rounded-xl border border-mono-100 p-4">
        <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-mono-900"><input type="checkbox" wire:model.live="form.includes_writ" class="rounded border-mono-200 text-primary-500"> Este valor inclui crédito que eu já comprei (requisitório)</label>
        @if ($form['includes_writ'])
            <p class="text-xs text-mono-600">Essa parte não é receita nem repasse ao cliente: o requisitório passa para <strong>Recebido</strong> na data desta entrada, com o valor informado, e o prazo real é calculado desde o pagamento da aquisição. A entrada continua única no banco.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label for="process-writ" class="mb-2 block text-sm text-mono-600">Requisitório comprado *</label><select id="process-writ" wire:model.live="form.writ_id" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">Selecionar</option>@foreach ($writs as $writ)<option value="{{ $writ->id }}">{{ $writ->beneficiaryName() }} · {{ $writ->process_number ?: '#'.$writ->id }} · {{ $writ->stageLabel() }}</option>@endforeach</select></div>
                <x-jr.input label="Valor do crédito do requisitório (R$) *" name="form.writ_amount" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.writ_amount" />
            </div>
            @if ($writs->isEmpty())<p class="text-xs text-error">Nenhum requisitório comprado (Pago, Peticionar ou Aguardando recebimento) disponível.</p>@endif
        @endif
    </section>
    <section class="space-y-4 rounded-xl border border-mono-100 bg-mono-50 p-4">
        <h4 class="font-semibold text-mono-900">1. Cliente e honorários</h4>
        <div class="flex gap-2" role="group" aria-label="Informar honorários por">
            @foreach (['amount' => 'Valor (R$)', 'percent' => 'Percentual (%)'] as $mode => $label)<button type="button" wire:click="setMode('fees_mode', '{{ $mode }}')" aria-pressed="{{ $form['fees_mode'] === $mode ? 'true' : 'false' }}" class="rounded-pill border px-4 py-2 text-sm {{ $form['fees_mode'] === $mode ? 'border-primary-500 bg-primary-50 text-primary-600' : 'border-mono-200 bg-white text-mono-600' }}">{{ $label }}</button>@endforeach
        </div>
        <div class="grid items-end gap-4 md:grid-cols-2">
            <div wire:key="process-fees-{{ $form['fees_mode'] }}">
                @if ($form['fees_mode'] === 'percent')
                    <x-jr.input label="Honorários (% do recebimento)" name="form.fees" type="text" x-decimal="2" wire:model.live.debounce.300ms="form.fees" required />
                @else
                    <x-jr.input label="Honorários (R$)" name="form.fees" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.fees" required />
                @endif
            </div>
    @if ($preview)<p class="pb-3 text-sm text-mono-900">Valor bruto do cliente: <strong>R$ {{ number_format($preview['client'] / 100, 2, ',', '.') }}</strong></p>@endif
        </div>
    </section>
    <section class="space-y-4 rounded-xl border border-mono-100 bg-mono-50 p-4">
        <h4 class="font-semibold text-mono-900">2. Distribuição dos honorários @if ($preview) · R$ {{ number_format($preview['fees'] / 100, 2, ',', '.') }} @endif</h4>
        <div class="flex gap-2" role="group" aria-label="Informar comissões por">
            @foreach (['amount' => 'Valor (R$)', 'percent' => 'Percentual (%)'] as $mode => $label)<button type="button" wire:click="setMode('commission_mode', '{{ $mode }}')" aria-pressed="{{ $form['commission_mode'] === $mode ? 'true' : 'false' }}" class="rounded-pill border px-4 py-2 text-sm {{ $form['commission_mode'] === $mode ? 'border-primary-500 bg-primary-50 text-primary-600' : 'border-mono-200 bg-white text-mono-600' }}">{{ $label }}</button>@endforeach
        </div>
        <p class="text-xs text-mono-600">Este seletor vale para a comissão do escritório (percentual sobre os honorários). Cada corretor tem a própria base e modo abaixo.</p>
        <p class="text-sm text-mono-600">Ao salvar, o valor líquido do cliente ficará negativo na conta <strong>Cliente</strong> e a parte do escritório na conta <strong>Mota e Silva Advogados</strong>, indicando os valores a repassar. O dinheiro permanece na conta bancária até o pagamento. Registre pagamentos totais ou parciais em <strong>Contas → Gerenciar repasses</strong>. Cadastre essas contas com o tipo <strong>Repasse</strong> em <a href="{{ route('banking.accounts.index') }}" class="font-semibold text-primary-600">Contas</a>.</p>

        <div class="grid gap-4 md:grid-cols-2">
            <div wire:key="process-office-amount-{{ $form['commission_mode'] }}">
                @if ($form['commission_mode'] === 'percent')
                    <x-jr.input label="Comissão do escritório (%)" name="form.office_amount" type="text" x-decimal="2" wire:model.live.debounce.300ms="form.office_amount" required />
                @else
                    <x-jr.input label="Comissão do escritório (R$)" name="form.office_amount" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.office_amount" required />
                @endif
            </div>
            <div class="rounded-xl bg-white p-3 text-sm text-mono-600">Escritório<strong class="mt-1 block text-mono-900">Mota e Silva Advogados</strong></div>
            <x-banking.category-picker :categories="$categories" :selected-id="$form['category_id'] ?? null" model="form.category_id" id="process-category" label="Categoria da minha receita" />
        </div>
    </section>
    <section class="space-y-4 rounded-xl border border-mono-100 p-4">
        <div class="flex items-center justify-between"><h4 class="font-semibold text-mono-900">3. Corretores <span class="text-sm font-normal text-mono-500">(opcional)</span></h4><button type="button" wire:click="addBroker" class="text-sm font-semibold text-primary-600">+ Adicionar corretor</button></div>
        <p class="text-xs text-mono-600">Cada corretor gera uma comissão própria no módulo Corretores. Percentual sobre os honorários (padrão) ou sobre o valor bruto, ou valor fixo. A compensação de adiantamento é opcional e abate só daquele corretor: marque "Compensar automaticamente" para abater do mais antigo para o mais novo, ou informe os valores à mão.</p>
        @forelse ($form['brokers'] as $index => $row)
            <div wire:key="broker-row-{{ $row['key'] ?? $index }}" class="space-y-3 rounded-xl border border-mono-100 bg-mono-50 p-3">
                <div class="grid items-end gap-3 md:grid-cols-[1.4fr_1fr_1fr_1fr_auto]">
                    <div><label class="mb-1 block text-xs text-mono-600" for="broker-{{ $index }}">Corretor {{ $index + 1 }}</label><select id="broker-{{ $index }}" wire:model.live="form.brokers.{{ $index }}.broker_id" class="h-11 w-full rounded-xl border-mono-200 text-sm"><option value="">Selecionar</option>@foreach ($brokers as $broker)<option value="{{ $broker->id }}">{{ $broker->name }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-xs text-mono-600" for="broker-base-{{ $index }}">Base</label><select id="broker-base-{{ $index }}" wire:model.live="form.brokers.{{ $index }}.base" class="h-11 w-full rounded-xl border-mono-200 text-sm"><option value="fees">Honorários</option><option value="gross">Valor bruto</option></select></div>
                    <div><label class="mb-1 block text-xs text-mono-600" for="broker-mode-{{ $index }}">Modo</label><select id="broker-mode-{{ $index }}" wire:model.live="form.brokers.{{ $index }}.mode" class="h-11 w-full rounded-xl border-mono-200 text-sm"><option value="percent">Percentual (%)</option><option value="fixed">Valor fixo (R$)</option></select></div>
                    <div><label class="mb-1 block text-xs text-mono-600" for="broker-value-{{ $index }}">{{ ($row['mode'] ?? 'percent') === 'percent' ? 'Percentual (%)' : 'Valor (R$)' }}</label>@if (($row['mode'] ?? 'percent') === 'percent')<input id="broker-value-{{ $index }}" wire:key="broker-value-percent-{{ $row['key'] }}" type="text" x-decimal="2" wire:model.live.debounce.300ms="form.brokers.{{ $index }}.value" class="h-11 w-full rounded-xl border-mono-200 text-sm">@else<input id="broker-value-{{ $index }}" wire:key="broker-value-fixed-{{ $row['key'] }}" type="text" x-money wire:model.live.debounce.300ms="form.brokers.{{ $index }}.value" class="h-11 w-full rounded-xl border-mono-200 text-sm">@endif</div>
                    <button type="button" wire:click="removeBroker({{ $index }})" class="h-11 px-2 text-sm text-error" aria-label="Remover corretor {{ $index + 1 }}">Remover</button>
                </div>
                <p class="text-sm text-mono-900">Valor calculado: <strong>R$ {{ number_format(($preview['brokers'][collect($form['brokers'])->take($index + 1)->filter(fn ($r) => ! empty($r['broker_id']))->count() - 1] ?? 0) / 100, 2, ',', '.') }}</strong></p>
                @if (! empty($row['broker_id']) && ($advances[(int) $row['broker_id']] ?? collect())->isNotEmpty())
                    @php
                        $openRows = $advances[(int) $row['broker_id']];
                        $isAuto = ! empty($row['auto']);
                        $allocation = $autoAllocations[$index] ?? [];
                        $usedCents = $isAuto ? array_sum($allocation) : collect($row['advances'] ?? [])->sum(fn ($amount) => is_numeric($amount) ? (int) round((float) $amount * 100) : 0);
                        $openCents = $openRows->sum('available');
                        $commissionCents = (int) ($preview['brokers'][collect($form['brokers'])->take($index + 1)->filter(fn ($r) => ! empty($r['broker_id']))->count() - 1] ?? 0);
                    @endphp
                    <div class="space-y-2 rounded-xl border border-mono-100 bg-white p-3 text-sm" wire:key="advances-box-{{ $row['key'] ?? $index }}">
                        <label class="flex cursor-pointer items-start gap-2">
                            <input type="checkbox" wire:model.live="form.brokers.{{ $index }}.auto" class="mt-0.5 h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500" data-test="auto-compensar-{{ $index }}">
                            <span><strong class="text-mono-900">Compensar automaticamente com os adiantamentos</strong><span class="block text-xs text-mono-600">Abate do mais antigo para o mais novo até o valor da comissão. Em aberto: R$ {{ number_format($openCents / 100, 2, ',', '.') }}</span></span>
                        </label>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[300px] text-[11px] sm:text-xs">
                                <thead><tr class="text-left text-mono-600"><th class="py-1 pr-2">Adiantamento</th><th class="py-1 pr-2 text-right">Disponível</th><th class="py-1 pr-2 text-right">Compensar</th><th class="py-1 text-right"><span class="sm:hidden">Fica</span><span class="hidden sm:inline">Fica a compensar</span></th></tr></thead>
                                <tbody>
                                @foreach ($openRows as $open)
                                    @php
                                        $advance = $open['advance'];
                                        $take = $isAuto ? ($allocation[$advance->id] ?? 0) : (is_numeric($row['advances'][$advance->id] ?? null) ? (int) round((float) $row['advances'][$advance->id] * 100) : 0);
                                    @endphp
                                    <tr wire:key="advance-choice-{{ $index }}-{{ $advance->id }}" class="border-t border-mono-100 align-middle {{ $take > 0 ? 'bg-primary-50' : '' }}">
                                        <td class="py-1.5 pr-2"><strong>{{ $advance->date->format('d/m/Y') }}</strong>@if ($advance->notes)<span class="block text-mono-600">{{ $advance->notes }}</span>@endif</td>
                                        <td class="py-1.5 pr-2 text-right">R$ {{ number_format($open['available'] / 100, 2, ',', '.') }}</td>
                                        <td class="py-1.5 pr-2 text-right">
                                            @if ($isAuto)
                                                <strong class="{{ $take > 0 ? 'text-primary-600' : 'text-mono-400' }}">R$ {{ number_format($take / 100, 2, ',', '.') }}</strong>
                                            @else
                                                <input type="text" inputmode="numeric" x-money aria-label="Compensar adiantamento de {{ $advance->date->format('d/m/Y') }}" wire:model.live.debounce.300ms="form.brokers.{{ $index }}.advances.{{ $advance->id }}" class="h-9 w-24 rounded-lg border-mono-200 px-2 text-right text-xs sm:w-28">
                                            @endif
                                        </td>
                                        <td class="py-1.5 text-right {{ $open['available'] - $take > 0 ? 'text-mono-900' : 'text-success' }}">R$ {{ number_format(max(0, $open['available'] - $take) / 100, 2, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot><tr class="border-t border-mono-200 font-semibold"><td class="py-1.5 pr-2">Total</td><td class="py-1.5 pr-2 text-right">R$ {{ number_format($openCents / 100, 2, ',', '.') }}</td><td class="py-1.5 pr-2 text-right text-primary-600">R$ {{ number_format($usedCents / 100, 2, ',', '.') }}</td><td class="py-1.5 text-right">R$ {{ number_format(max(0, $openCents - $usedCents) / 100, 2, ',', '.') }}</td></tr></tfoot>
                            </table>
                        </div>
                        <p class="text-xs text-mono-600">Comissão R$ {{ number_format($commissionCents / 100, 2, ',', '.') }} − compensado R$ {{ number_format($usedCents / 100, 2, ',', '.') }} = <strong>a pagar ao corretor R$ {{ number_format(max(0, $commissionCents - $usedCents) / 100, 2, ',', '.') }}</strong></p>
                    </div>
                @endif
            </div>
        @empty<p class="text-sm text-mono-500">Sem corretor. Os honorários ficam entre você e o escritório.</p>@endforelse
    </section>
    @if ($expenses->isNotEmpty())
    <section class="space-y-4 rounded-xl border border-mono-100 p-4">
        <h4 class="font-semibold text-mono-900">4. Despesas a descontar do cliente <span class="text-sm font-normal text-mono-500">(opcional)</span></h4>
        <p class="text-sm text-mono-600">Despesas disponíveis dos corretores selecionados. Informe quanto recuperar de cada uma; deixe em branco para não descontar.</p>
        @foreach ($expenses as $expense)
            <div wire:key="expense-choice-{{ $expense->id }}" class="grid items-center gap-3 border-t border-mono-100 pt-3 sm:grid-cols-[1fr_180px]">
                <div class="text-sm"><strong>{{ $expense->description }}</strong><p class="text-mono-600">{{ $expense->broker?->name }} · {{ $expense->date->format('d/m/Y') }} · Disponível: R$ {{ number_format($expense->remainingCents() / 100, 2, ',', '.') }}</p></div>
                <x-jr.input label="Descontar (R$)" :name="'form.expenses.'.$expense->id" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.expenses.{{ $expense->id }}" />
            </div>
        @endforeach
    </section>
    @endif
    <section class="space-y-3 rounded-xl border border-mono-100 bg-mono-50 p-4">
        <h4 class="font-semibold text-mono-900">Adiantamentos do cliente a descontar (opcional)</h4>
        <p class="text-sm text-mono-600">Selecione o contato do cliente e informe quanto descontar de cada valor a receber. O desconto reduz a parte do cliente e quita o adiantamento, sem aumentar sua receita.</p>
        @forelse ($clientAdvances as $advance)
            <div class="grid items-center gap-3 md:grid-cols-2" wire:key="client-advance-{{ $advance->id }}"><div class="text-sm"><strong>{{ $advance->description }}</strong><p class="text-mono-600">Saldo: R$ {{ number_format($advance->remainingCents() / 100, 2, ',', '.') }} @if ($advance->process_reference) · {{ $advance->process_reference }} @endif</p></div><x-jr.input label="Descontar (R$)" :name="'form.receivables.'.$advance->id" x-money inputmode="numeric" wire:model.live.debounce.300ms="form.receivables.{{ $advance->id }}" /></div>
        @empty <p class="text-xs text-mono-600">{{ ($form['client_contact_id'] ?? null) ? 'Este cliente não possui adiantamentos em aberto.' : 'Selecione o contato para consultar os valores a receber.' }}</p> @endforelse
    </section>
    @if ($preview)
        <div class="grid gap-3 rounded-xl bg-primary-50 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" aria-live="polite">
            @php
                $summary = ['Entrada bruta' => $preview['total']];
                if ($writCents > 0) { $summary['Requisitório comprado (não é receita)'] = $writCents; }
                $summary['Cliente líquido a repassar'] = $preview['client'] - $writCents - $recovered - (int) round($clientOffset * 100);
                if ($recovered) { $summary['Despesas recuperadas'] = $recovered; }
                $summary['Honorários'] = $preview['fees'];
                $summary['Comissão do escritório'] = $preview['office'];
                $brokerNames = $brokers->keyBy('id');
                foreach (collect($form['brokers'])->filter(fn ($r) => ! empty($r['broker_id']))->values() as $i => $r) { $summary['Corretor · '.($brokerNames[(int) $r['broker_id']]->name ?? '#'.$r['broker_id'])] = $preview['brokers'][$i] ?? 0; }
                if ($offset) { $summary['Adiantamentos compensados'] = $offset; }
                $summary['Minha receita'] = $preview['own'];
            @endphp
            @foreach ($summary as $label => $value)
                <div class="{{ $value < 0 ? 'text-error' : 'text-mono-900' }}">{{ $label }}<strong class="mt-1 block">R$ {{ number_format($value / 100, 2, ',', '.') }}</strong></div>
            @endforeach
        </div>
    @endif
    @if ($previewError)<p class="text-sm text-error" role="alert">{{ $previewError }}</p>@endif
    @foreach ($errors->all() as $message)<p class="text-sm text-error" role="alert">{{ $message }}</p>@endforeach
    <button type="submit" wire:loading.attr="disabled" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Salvar recebimento de processo</button>
</form>

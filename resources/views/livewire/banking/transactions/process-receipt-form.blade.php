<?php

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $receiptId;
    public array $form = [
        'broker_id' => null, 'client_name' => '', 'category_id' => null,
        'fees' => '0.00', 'fees_mode' => 'amount', 'broker_amount' => '0.00', 'office_amount' => '0.00',
        'commission_mode' => 'amount', 'expenses' => [], 'advances' => [],
    ];

    public function mount(int $receiptId): void
    {
        app(\App\Domains\Brokers\Services\BrokerProfileService::class)->synchronizeDirectory();
        $this->receiptId = $receiptId;
        $receipt = Transaction::findOrFail($receiptId);
        app(\App\Domains\Banking\Services\ReceiptDestinationService::class)->eligible($receipt);
        $parts = $receipt->destinations()->with('commission')->get();
        $process = $receipt->processReceipt;
        $this->form = array_replace($this->form, [
            'broker_id' => $process?->broker_id ?? $parts->firstWhere('kind', 'broker')?->commission?->broker_id,
            'client_name' => $parts->firstWhere('kind', 'client')?->beneficiary ?? '',
            'category_id' => $process?->category_id,
            'fees' => $process ? (string) $process->fees_amount : number_format($parts->whereIn('kind', ['own', 'broker', 'office'])->sum('amount'), 2, '.', ''),
            'broker_amount' => number_format($parts->where('kind', 'broker')->sum('amount'), 2, '.', ''),
            'office_amount' => number_format($parts->where('kind', 'office')->sum('amount'), 2, '.', ''),
        ]);
    }

    public function updatedForm($value, ?string $key = null): void
    {
        if ($key === 'broker_id') { $this->form['expenses'] = []; $this->form['advances'] = []; }
        if (empty($this->form['broker_id'])) {
            $this->form['broker_amount'] = '0.00';
            $this->form['expenses'] = [];
            $this->form['advances'] = [];
        }
        $this->resetValidation();
    }

    public function setMode(string $field, string $mode, ProcessReceiptService $service): void
    {
        if (! in_array($field, ['fees_mode', 'commission_mode'], true) || ! in_array($mode, ['amount', 'percent'], true)) { return; }
        if ($this->form[$field] === $mode) { return; }
        try {
            $values = $service->calculate(Transaction::findOrFail($this->receiptId), $this->form);
            $base = $field === 'fees_mode' ? $values['total'] : $values['fees'];
            foreach ($field === 'fees_mode' ? ['fees' => 'fees'] : ['broker_amount' => 'broker', 'office_amount' => 'office'] as $input => $result) {
                $this->form[$input] = $mode === 'amount' ? $service->money($values[$result]) : number_format($base ? $values[$result] * 100 / $base : 0, 2, '.', '');
            }
            $this->form[$field] = $mode;
        } catch (\DomainException $e) { $this->addError('division', $e->getMessage()); }
    }

    public function save(ProcessReceiptService $service): void
    {
        try { $service->save(Transaction::findOrFail($this->receiptId), $this->form); }
        catch (\DomainException $e) { $this->addError('division', $e->getMessage()); return; }
        $this->dispatch('process-receipt-saved', receiptId: $this->receiptId);
        $this->dispatch('receipt-destination-updated');
    }

    public function with(ProcessReceiptService $service): array
    {
        $receipt = Transaction::findOrFail($this->receiptId);
        $preview = null;
        $previewError = null;
        try { $preview = $service->calculate($receipt, $this->form); }
        catch (\DomainException $e) { $previewError = $e->getMessage(); }
        $expenses = $this->form['broker_id'] ? BrokerProcessExpense::where('broker_id', $this->form['broker_id'])->orderBy('date')->get()->filter(fn ($expense) => $expense->remainingCents() > 0) : collect();
        $advances = $this->form['broker_id'] ? BrokerAdvance::where('broker_id', $this->form['broker_id'])->orderBy('date')->get()->filter(fn ($advance) => $advance->remainingBalance() > 0) : collect();
        $recovered = empty($this->form['broker_id']) ? 0 : collect($this->form['expenses'])->sum(fn ($amount) => is_numeric($amount) ? (int) round((float) $amount * 100) : 0);
        $offset = empty($this->form['broker_id']) ? 0 : collect($this->form['advances'])->sum(fn ($amount) => is_numeric($amount) ? (int) round((float) $amount * 100) : 0);
        return compact('preview', 'previewError', 'expenses', 'advances', 'recovered', 'offset') + [
            'brokers' => Broker::selectable()->orderBy('name')->get(),
            'categories' => Category::active()->where('type', 'income')->orderBy('name')->get(),
        ];
    }
}; ?>

<form wire:submit="save" class="space-y-5">
    <p class="text-sm text-mono-600">Separe o valor do cliente e os honorários. Sem corretor, os honorários são divididos entre você e o escritório. Se houver corretor, selecione-o para lançar a comissão e escolher os descontos disponíveis.</p>
    <div class="grid gap-4 md:grid-cols-2">
        <div><label for="process-broker" class="mb-2 block text-sm text-mono-600">Corretor (opcional)</label><select id="process-broker" wire:model.live="form.broker_id" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">Sem corretor</option>@foreach ($brokers as $broker)<option value="{{ $broker->id }}">{{ $broker->name }}</option>@endforeach</select></div>
        <x-jr.input label="Cliente ou processo *" name="form.client_name" wire:model="form.client_name" maxlength="200" required />
    </div>
    <section class="space-y-4 rounded-xl border border-mono-100 bg-mono-50 p-4">
        <h4 class="font-semibold text-mono-900">1. Cliente e honorários</h4>
        <div class="flex gap-2" role="group" aria-label="Informar honorários por">
            @foreach (['amount' => 'Valor (R$)', 'percent' => 'Percentual (%)'] as $mode => $label)<button type="button" wire:click="setMode('fees_mode', '{{ $mode }}')" aria-pressed="{{ $form['fees_mode'] === $mode ? 'true' : 'false' }}" class="rounded-pill border px-4 py-2 text-sm {{ $form['fees_mode'] === $mode ? 'border-primary-500 bg-primary-50 text-primary-600' : 'border-mono-200 bg-white text-mono-600' }}">{{ $label }}</button>@endforeach
        </div>
        <div class="grid items-end gap-4 md:grid-cols-2">
            <div wire:key="process-fees-{{ $form['fees_mode'] }}">
                @if ($form['fees_mode'] === 'percent')
                    <x-jr.input label="Honorários (% do recebimento)" name="form.fees" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="form.fees" required />
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
        <p class="text-xs text-mono-600">Os percentuais das comissões são calculados sobre os honorários.</p>
        <p class="text-sm text-mono-600">Ao salvar, o valor líquido do cliente ficará negativo na conta <strong>Cliente</strong> e a parte do escritório na conta <strong>Mota e Silva Advogados</strong>, indicando os valores a repassar. O dinheiro permanece na conta bancária até o pagamento. Registre pagamentos totais ou parciais em <strong>Contas → Gerenciar repasses</strong>. Cadastre essas contas com o tipo <strong>Repasse</strong> em <a href="{{ route('banking.accounts.index') }}" class="font-semibold text-primary-600">Contas</a>.</p>

        <div class="grid gap-4 md:grid-cols-2">
            @if (! empty($form['broker_id']))
            <div wire:key="process-broker-amount-{{ $form['commission_mode'] }}">
                @if ($form['commission_mode'] === 'percent')
                    <x-jr.input label="Comissão do corretor (%)" name="form.broker_amount" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="form.broker_amount" required />
                @else
                    <x-jr.input label="Comissão do corretor (R$)" name="form.broker_amount" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.broker_amount" required />
                @endif
            </div>
            @endif
            <div wire:key="process-office-amount-{{ $form['commission_mode'] }}">
                @if ($form['commission_mode'] === 'percent')
                    <x-jr.input label="Comissão do escritório (%)" name="form.office_amount" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="form.office_amount" required />
                @else
                    <x-jr.input label="Comissão do escritório (R$)" name="form.office_amount" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.office_amount" required />
                @endif
            </div>
            <div class="rounded-xl bg-white p-3 text-sm text-mono-600">Escritório<strong class="mt-1 block text-mono-900">Mota e Silva Advogados</strong></div>
            <x-banking.category-picker :categories="$categories" model="form.category_id" id="process-category" label="Categoria da minha receita" />
        </div>
    </section>
    @if (! empty($form['broker_id']))
    <section class="space-y-4 rounded-xl border border-mono-100 p-4">
        <h4 class="font-semibold text-mono-900">3. Despesas a descontar do cliente <span class="text-sm font-normal text-mono-500">(opcional)</span></h4>
        <p class="text-sm text-mono-600">Despesas disponíveis do corretor. Informe quanto recuperar de cada uma; deixe em branco para não descontar.</p>
        @forelse ($expenses as $expense)
            <div wire:key="expense-choice-{{ $expense->id }}" class="grid items-center gap-3 border-t border-mono-100 pt-3 sm:grid-cols-[1fr_180px]">
                <div class="text-sm"><strong>{{ $expense->description }}</strong><p class="text-mono-600">{{ $expense->date->format('d/m/Y') }} · Disponível: R$ {{ number_format($expense->remainingCents() / 100, 2, ',', '.') }}</p></div>
                <x-jr.input label="Descontar (R$)" :name="'form.expenses.'.$expense->id" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.expenses.{{ $expense->id }}" />
            </div>
        @empty<p class="text-sm text-mono-500">{{ $form['broker_id'] ? 'Nenhuma despesa disponível para este corretor.' : 'Selecione o corretor para consultar as despesas.' }}</p>@endforelse
    </section>
    <section class="space-y-4 rounded-xl border border-mono-100 p-4">
        <h4 class="font-semibold text-mono-900">4. Adiantamentos a compensar na comissão <span class="text-sm font-normal text-mono-500">(opcional)</span></h4>
        <p class="text-sm text-mono-600">Escolha quanto abater da comissão do corretor. Nenhum adiantamento será utilizado automaticamente neste recebimento.</p>
        @forelse ($advances as $advance)
            <div wire:key="advance-choice-{{ $advance->id }}" class="grid items-center gap-3 border-t border-mono-100 pt-3 sm:grid-cols-[1fr_180px]">
                <div class="text-sm"><strong>Adiantamento de {{ $advance->date->format('d/m/Y') }}</strong><p class="text-mono-600">{{ $advance->notes }} · Disponível: R$ {{ number_format($advance->remainingBalance(), 2, ',', '.') }}</p></div>
                <x-jr.input label="Compensar (R$)" :name="'form.advances.'.$advance->id" type="text" inputmode="numeric" x-money wire:model.live.debounce.300ms="form.advances.{{ $advance->id }}" />
            </div>
        @empty<p class="text-sm text-mono-500">{{ $form['broker_id'] ? 'Nenhum adiantamento disponível para este corretor.' : 'Selecione o corretor para consultar os adiantamentos.' }}</p>@endforelse
    </section>
    @endif
    @if ($preview)
        <div class="grid gap-3 rounded-xl bg-primary-50 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" aria-live="polite">
            @foreach (['Cliente líquido' => $preview['client'] - $recovered, 'Despesas recuperadas' => $recovered, ...(! empty($form['broker_id']) ? ['Corretor líquido' => $preview['broker'] - $offset, 'Adiantamentos compensados' => $offset] : []), 'Comissão do escritório' => $preview['office'], 'Minha receita' => $preview['own']] as $label => $value)
                <div class="{{ $value < 0 ? 'text-error' : 'text-mono-900' }}">{{ $label }}<strong class="mt-1 block">R$ {{ number_format($value / 100, 2, ',', '.') }}</strong></div>
            @endforeach
        </div>
    @endif
    @if ($previewError)<p class="text-sm text-error" role="alert">{{ $previewError }}</p>@endif
    @foreach ($errors->all() as $message)<p class="text-sm text-error" role="alert">{{ $message }}</p>@endforeach
    <button type="submit" wire:loading.attr="disabled" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Salvar recebimento de processo</button>
</form>

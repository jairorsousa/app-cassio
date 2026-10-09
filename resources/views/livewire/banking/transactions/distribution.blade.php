<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerLedgerDeletionService;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $receiptId;
    public array $rows = [];
    public bool $editing = false;
    #[Locked]
    public ?int $payPartId = null;
    public string $payMode = 'new';
    public string $payAmount = '';
    public string $payDate = '';
    public ?int $payAccountId = null;
    public ?int $payExistingId = null;

    public function mount(int $receiptId): void
    {
        $this->receiptId = $receiptId;
        app(ReceiptDestinationService::class)->eligible($this->receipt());
        $this->loadRows();
    }

    private function receipt(): Transaction
    {
        return Transaction::findOrFail($this->receiptId);
    }

    private function loadRows(): void
    {
        $parts = $this->receipt()->destinations()->with('commission')->get();
        $this->editing = $parts->isEmpty();
        $this->rows = $parts->isEmpty() ? collect(['client', 'broker', 'office', 'own'])->map(fn ($kind) => $this->newRow($kind))->all() : $parts->map(fn ($part) => [
            'key' => (string) Str::uuid(), 'kind' => $part->kind, 'beneficiary' => $part->beneficiary,
            'amount' => (string) $part->amount, 'broker_id' => $part->commission?->broker_id,
            'case_type_id' => $part->commission?->case_type_id, 'commission_id' => $part->commission_id,
        ])->all();
    }

    private function newRow(string $kind = 'client'): array
    {
        return ['key' => (string) Str::uuid(), 'kind' => $kind, 'beneficiary' => '', 'amount' => '', 'broker_id' => null, 'case_type_id' => null, 'commission_id' => null];
    }

    public function addRow(): void
    {
        if (count($this->rows) < 20) { $this->rows[] = $this->newRow(); }
    }

    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index]) && $this->rows[$index]['kind'] !== 'own' && count($this->rows) > 2) { array_splice($this->rows, $index, 1); }
    }

    public function editDivision(): void
    {
        $this->loadRows();
        $this->editing = true;
        $this->payPartId = null;
    }

    public function save(ReceiptDestinationService $service): void
    {
        $this->validate([
            'rows' => 'required|array|min:2|max:20',
            'rows.*.kind' => 'required|in:client,broker,office,own',
            'rows.*.beneficiary' => 'nullable|string|max:200',
            'rows.*.amount' => 'required|string',
            'rows.*.broker_id' => 'nullable|integer|exists:brokers,id',
            'rows.*.case_type_id' => 'nullable|integer|exists:case_types,id',
            'rows.*.commission_id' => 'nullable|integer|exists:broker_commissions,id',
        ]);
        try { $service->replace($this->receipt(), $this->rows); }
        catch (\DomainException $e) { $this->addError('division', $e->getMessage()); return; }
        $this->loadRows();
        $this->resetValidation();
        $this->dispatch('receipt-destination-updated');
    }

    public function clear(ReceiptDestinationService $service): void
    {
        try { $service->clear($this->receipt()); }
        catch (\DomainException $e) { $this->addError('division', $e->getMessage()); return; }
        $this->loadRows();
        $this->dispatch('receipt-destination-updated');
    }

    public function startPayment(int $id): void
    {
        $part = $this->receipt()->destinations()->findOrFail($id);
        abort_if($part->kind === 'own', 422);
        $this->payPartId = $part->id;
        $this->payMode = 'new';
        $this->payExistingId = null;
        $this->payAmount = number_format($part->remainingCents() / 100, 2, '.', '');
        $this->payDate = now('America/Sao_Paulo')->format('Y-m-d');
        $this->payAccountId = $this->receipt()->bank_account_id;
        $this->resetValidation();
    }

    public function pay(ReceiptDestinationService $service): void
    {
        $this->validate([
            'payMode' => 'required|in:new,existing', 'payAmount' => 'required|numeric|min:0.01',
            'payDate' => 'required|date|before_or_equal:'.now('America/Sao_Paulo')->format('Y-m-d'),
            'payAccountId' => 'required|integer|exists:bank_accounts,id',
            'payExistingId' => [$this->payMode === 'existing' ? 'required' : 'nullable', 'integer', 'exists:transactions,id'],
        ]);
        $part = $this->receipt()->destinations()->findOrFail($this->payPartId);
        try { $service->pay($part, $this->payAmount, $this->payDate, $this->payAccountId, $this->payMode === 'existing' ? $this->payExistingId : null); }
        catch (\DomainException $e) { $this->addError('payment', $e->getMessage()); return; }
        $this->payPartId = null;
        $this->dispatch('receipt-destination-updated');
    }

    public function undoPayment(int $id, ReceiptDestinationService $service): void
    {
        $payment = \App\Domains\Banking\Models\ReceiptDestinationPayment::whereHas('destination', fn ($q) => $q->where('receipt_id', $this->receiptId))->findOrFail($id);
        $service->undo($payment);
        $this->payPartId = null;
        $this->dispatch('receipt-destination-updated');
    }

    public function undoBrokerPayment(int $id, BrokerLedgerDeletionService $service): void
    {
        $ids = $this->receipt()->destinations()->where('kind', 'broker')->pluck('commission_id');
        $payment = BrokerCommissionPayment::whereIn('commission_id', $ids)->findOrFail($id);
        $service->deletePayment($payment);
        $this->receipt()->touch();
        $this->payPartId = null;
        $this->dispatch('receipt-destination-updated');
    }

    public function close(): void
    {
        $this->dispatch('receipt-destination-closed');
    }

    public function cancelPayment(): void
    {
        $this->payPartId = null;
        $this->resetValidation();
    }

    public function with(): array
    {
        $receipt = $this->receipt();
        $parts = $receipt->destinations()->with('payments.transaction', 'commission.payments.transaction.destinationPayment', 'commission.broker')->get();
        return [
            'receipt' => $receipt, 'parts' => $parts,
            'canEdit' => ! $parts->contains(fn ($part) => $part->paidCents() > 0 || $part->payments->isNotEmpty()),
            'ownAmount' => $parts->where('kind', 'own')->sum('amount'),
            'reservedAmount' => $parts->sum(fn ($part) => $part->remainingCents()) / 100,
            'brokers' => Broker::active()->orderBy('name')->get(),
            'caseTypes' => CaseType::active()->orderBy('name')->get(),
            'commissions' => BrokerCommission::with('broker')->whereDoesntHave('receiptDestination', fn ($q) => $q->where('receipt_id', '!=', $this->receiptId))->orderByDesc('reference_date')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'payPart' => $parts->firstWhere('id', $this->payPartId),
            'existingExpenses' => $this->payPartId && $this->payMode === 'existing' ? Transaction::where('type', 'expense')->where('status', 'settled')->where('amount', '>', 0)->where('bank_account_id', $this->payAccountId)->whereNull('credit_card_id')->whereNull('source_type')->whereDoesntHave('destinationPayment')->orderByDesc('date')->limit(100)->get() : collect(),
        ];
    }
}; ?>

<div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" x-data @keydown.escape.window="$wire.close()">
    <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="close" aria-label="Fechar destinação"></button>
    <div role="dialog" aria-modal="true" aria-labelledby="receipt-destination-title" x-trap.inert.noscroll="true" class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-elevated">
        <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
            <h3 id="receipt-destination-title" class="text-lg font-bold text-mono-900">Destinação do recebimento</h3>
            <button type="button" wire:click="close" aria-label="Fechar"><span class="material-icons-outlined text-mono-400">close</span></button>
        </div>
        <div class="space-y-5 overflow-y-auto px-6 py-5">
            <div class="rounded-xl bg-mono-50 p-4 text-sm text-mono-900">
                <strong>{{ $receipt->description }}</strong> · {{ $receipt->bankAccount?->name }} · {{ $receipt->date->format('d/m/Y') }}
                <p class="mt-1">Entrada recebida: <strong>R$ {{ number_format((float) $receipt->amount, 2, ',', '.') }}</strong></p>
            </div>
            @error('division') <p class="text-sm font-medium text-error" role="alert">{{ $message }}</p> @enderror
            @if ($editing)
                <p class="text-sm text-mono-600">Defina de quem é cada parte. Separar o valor não gera saída da conta: os repasses serão registrados quando forem pagos.</p>
                <form wire:submit="save" class="space-y-3">
                    @foreach ($rows as $index => $row)
                        <div wire:key="destination-row-{{ $row['key'] }}-{{ $index }}" class="space-y-3 rounded-xl border border-mono-100 bg-mono-50 p-4">
                            <div class="grid grid-cols-1 items-end gap-3 md:grid-cols-[180px_1fr_160px_36px]">
                                <div><label class="mb-2 block text-sm text-mono-600" for="destination-kind-{{ $index }}">Destinação</label>
                                    <select id="destination-kind-{{ $index }}" wire:model.live="rows.{{ $index }}.kind" class="h-12 w-full rounded-xl border-mono-200 text-sm">
                                        @foreach (['client' => 'Cliente', 'broker' => 'Corretor', 'office' => 'Escritório', 'own' => 'Minha parte'] as $kind => $name)<option value="{{ $kind }}">{{ $name }}</option>@endforeach
                                    </select>
                                </div>
                                @if ($row['kind'] === 'broker')
                                    <div><label class="mb-2 block text-sm text-mono-600" for="destination-broker-{{ $index }}">Corretor cadastrado *</label>
                                        <select id="destination-broker-{{ $index }}" wire:model.live="rows.{{ $index }}.broker_id" class="h-12 w-full rounded-xl border-mono-200 text-sm">
                                            <option value="">Selecionar corretor</option>@foreach ($brokers as $broker)<option value="{{ $broker->id }}">{{ $broker->name }}</option>@endforeach
                                        </select>
                                    </div>
                                @elseif ($row['kind'] === 'own')
                                    <p class="flex h-12 items-center text-sm text-mono-600">Valor que pertence a você</p>
                                @else
                                    <x-jr.input :label="$row['kind'] === 'client' ? 'Nome do cliente *' : 'Nome do escritório *'" :name="'rows.'.$index.'.beneficiary'" wire:model="rows.{{ $index }}.beneficiary" maxlength="200" required />
                                @endif
                                <x-jr.input label="Valor (R$) *" :name="'rows.'.$index.'.amount'" type="number" min="{{ $row['kind'] === 'own' ? '0' : '0.01' }}" step="0.01" inputmode="decimal" wire:model.live.debounce.300ms="rows.{{ $index }}.amount" required />
                                <button type="button" wire:click="removeRow({{ $index }})" @disabled($row['kind'] === 'own' || count($rows) <= 2) aria-label="Remover parte {{ $index + 1 }}" class="h-12 text-mono-400 disabled:opacity-30"><span class="material-icons-outlined">close</span></button>
                            </div>
                            @if ($row['kind'] === 'broker')
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div><label class="mb-2 block text-sm text-mono-600" for="destination-commission-{{ $index }}">Comissão</label>
                                        <select id="destination-commission-{{ $index }}" wire:model.live="rows.{{ $index }}.commission_id" class="h-12 w-full rounded-xl border-mono-200 text-sm">
                                            <option value="">Criar comissão pelo valor desta parte</option>
                                            @foreach ($commissions->where('broker_id', $row['broker_id']) as $commission)<option value="{{ $commission->id }}">#{{ $commission->id }} {{ $commission->name }} · R$ {{ number_format((float) $commission->commission_amount, 2, ',', '.') }}</option>@endforeach
                                        </select>
                                    </div>
                                    @if (empty($row['commission_id']))
                                        <div><label class="mb-2 block text-sm text-mono-600" for="destination-case-{{ $index }}">Tipo de caso *</label>
                                            <select id="destination-case-{{ $index }}" wire:model="rows.{{ $index }}.case_type_id" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">Selecionar</option>@foreach ($caseTypes as $caseType)<option value="{{ $caseType->id }}">{{ $caseType->name }}</option>@endforeach</select>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if (str_starts_with($field, 'rows'))<p class="text-sm text-error" role="alert">{{ $messages[0] }}</p>@endif
                    @endforeach
                    <button type="button" wire:click="addRow" @disabled(count($rows) >= 20) class="text-sm font-semibold text-primary-600 disabled:opacity-50">+ Adicionar beneficiário</button>
                    @php $remaining = (float) $receipt->amount - collect($rows)->sum(fn ($row) => is_numeric($row['amount'] ?? '') ? (float) $row['amount'] : 0); @endphp
                    <p class="text-sm {{ abs($remaining) < 0.005 ? 'text-green-600' : 'text-error' }}">{{ $remaining < -0.005 ? 'Excedente' : 'Restante' }}: <strong>R$ {{ number_format(abs($remaining), 2, ',', '.') }}</strong></p>
                    <button type="submit" wire:loading.attr="disabled" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white disabled:opacity-50">Salvar destinação</button>
                </form>
            @else
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl bg-green-50 p-4 text-sm text-green-800">Sua parte neste recebimento<strong class="mt-1 block text-xl">R$ {{ number_format($ownAmount, 2, ',', '.') }}</strong></div>
                    <div class="rounded-xl bg-orange-50 p-4 text-sm text-orange-800">Ainda a repassar<strong class="mt-1 block text-xl">R$ {{ number_format($reservedAmount, 2, ',', '.') }}</strong></div>
                    <div class="rounded-xl bg-blue-50 p-4 text-sm text-blue-800">Repassado ou compensado<strong class="mt-1 block text-xl">R$ {{ number_format($parts->sum(fn ($part) => $part->paidCents()) / 100, 2, ',', '.') }}</strong></div>
                </div>
                @foreach ($parts as $part)
                    <div class="space-y-3 rounded-xl border border-mono-100 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <div><strong class="text-mono-900">{{ ['client' => 'Cliente', 'broker' => 'Corretor', 'office' => 'Escritório', 'own' => 'Minha parte'][$part->kind] }} · {{ $part->beneficiary }}</strong>
                                <p class="mt-1 text-mono-600">R$ {{ number_format((float) $part->amount, 2, ',', '.') }} @if ($part->kind !== 'own') · Pendente: R$ {{ number_format($part->remainingCents() / 100, 2, ',', '.') }}@endif</p>
                                @if ($part->commission)<a class="text-primary-600 hover:underline" href="{{ route('brokers.show', $part->commission->broker_id) }}">Comissão #{{ $part->commission_id }} · Ver corretor</a>@endif
                            </div>
                            @if ($part->remainingCents() > 0)<button type="button" wire:click="startPayment({{ $part->id }})" class="h-10 rounded-pill bg-primary-50 px-4 font-semibold text-primary-600">Registrar repasse</button>
                            @elseif ($part->kind !== 'own')<span class="font-semibold text-green-600">Quitado</span>@endif
                        </div>
                        @foreach ($part->kind === 'broker' ? collect() : $part->payments as $payment)
                            <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-mono-50 p-3 text-xs text-mono-600">
                                <span>{{ $payment->transaction?->date->format('d/m/Y') }} · R$ {{ number_format((float) $payment->transaction?->amount, 2, ',', '.') }} · {{ $payment->transaction_created ? 'Repasse registrado' : 'Saída vinculada' }}</span>
                                <button type="button" wire:click="undoPayment({{ $payment->id }})" wire:confirm="{{ $payment->transaction_created ? 'Desfazer este repasse e remover a saída registrada?' : 'Desvincular este repasse? A saída original será preservada.' }}" class="text-error">{{ $payment->transaction_created ? 'Desfazer repasse' : 'Desvincular' }}</button>
                            </div>
                        @endforeach
                        @if ($part->commission)
                            @foreach ($part->commission->payments as $payment)
                                <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-mono-50 p-3 text-xs text-mono-600"><span>{{ $payment->paid_at->format('d/m/Y') }} · R$ {{ number_format((float) $payment->amount, 2, ',', '.') }} · {{ $payment->transaction?->destinationPayment ? 'Saída vinculada ao corretor' : 'Repasse ao corretor' }}</span><button type="button" wire:click="undoBrokerPayment({{ $payment->id }})" wire:confirm="{{ $payment->transaction?->destinationPayment ? 'Desvincular este repasse? A saída original será preservada.' : 'Desfazer o repasse ao corretor e remover a saída registrada?' }}" class="text-error">{{ $payment->transaction?->destinationPayment ? 'Desvincular' : 'Desfazer repasse' }}</button></div>
                            @endforeach
                            @if ($part->commission->settledAmount() > 0)<p class="text-xs text-mono-600">Compensado por adiantamentos: R$ {{ number_format($part->commission->settledAmount(), 2, ',', '.') }}. As compensações são gerenciadas no cadastro do corretor.</p>@endif
                        @endif
                    </div>
                @endforeach
                @if ($canEdit)
                    <div class="flex flex-wrap gap-4"><button type="button" wire:click="editDivision" class="text-sm font-semibold text-primary-600">Editar divisão</button><button type="button" wire:click="clear" wire:confirm="Remover esta destinação? Comissões criadas por ela e ainda sem repasses também serão removidas." class="text-sm text-error">Remover destinação</button></div>
                @else<p class="text-xs text-mono-600">A divisão fica protegida enquanto houver repasses ou compensações. Desfaça-os antes de alterar os beneficiários ou valores.</p>@endif
            @endif
            @if ($payPart)
                <form wire:submit="pay" class="space-y-4 rounded-xl border border-primary-100 bg-primary-50 p-4">
                    <h4 class="font-semibold text-mono-900">Repasse · {{ $payPart->beneficiary }}</h4>
                                            <div><label for="receipt-pay-mode" class="mb-2 block text-sm text-mono-600">Como registrar</label><select id="receipt-pay-mode" wire:model.live="payMode" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="new">Registrar uma nova saída na conta</option><option value="existing">Vincular uma saída já cadastrada ou importada</option></select></div>
                    <div><label for="receipt-pay-account" class="mb-2 block text-sm text-mono-600">Conta do repasse *</label><select id="receipt-pay-account" wire:model.live="payAccountId" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">Selecionar conta</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></div>
                    @if ($payMode === 'existing')
                        <div><label for="receipt-pay-existing" class="mb-2 block text-sm text-mono-600">Saída paga *</label><select id="receipt-pay-existing" wire:model="payExistingId" class="h-12 w-full rounded-xl border-mono-200 text-sm"><option value="">Selecionar saída</option>@foreach ($existingExpenses as $expense)<option value="{{ $expense->id }}">{{ $expense->date->format('d/m/Y') }} · {{ $expense->description }} · R$ {{ number_format((float) $expense->amount, 2, ',', '.') }}</option>@endforeach</select><p class="mt-2 text-xs text-mono-600">A saída selecionada será vinculada pelo valor integral. Nenhuma nova despesa será criada.</p></div>
                    @else
                        <div class="grid gap-3 md:grid-cols-2"><x-jr.input label="Valor do repasse (R$) *" name="payAmount" type="number" min="0.01" step="0.01" wire:model="payAmount" required /><x-jr.input label="Data do pagamento *" name="payDate" type="date" wire:model="payDate" required /></div>
                    @endif
                    @foreach ($errors->getMessages() as $field => $messages) @if ($field === 'payment' || str_starts_with($field, 'pay'))<p class="text-sm text-error" role="alert">{{ $messages[0] }}</p>@endif @endforeach
                    <div class="flex gap-3"><button type="submit" wire:loading.attr="disabled" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-white disabled:opacity-50">Confirmar repasse</button><button type="button" wire:click="cancelPayment" class="text-sm text-mono-600">Cancelar</button></div>
                </form>
            @endif
        </div>
        <div class="flex justify-end border-t border-mono-100 px-6 py-4"><button type="button" wire:click="close" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900">Fechar</button></div>
    </div>
</div>

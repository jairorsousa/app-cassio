<?php

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Services\BrokerLedgerDeletionService;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $receiptId;
    public array $rows = [];
    public bool $editing = false;
    public function mount(int $receiptId): void
    {
        app(\App\Domains\Brokers\Services\BrokerProfileService::class)->synchronizeDirectory();
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

    #[\Livewire\Attributes\On('process-receipt-saved')]
    public function processSaved(int $receiptId): void
    {
        if ($receiptId === $this->receiptId) { $this->loadRows(); $this->resetValidation(); }
    }

    public function undoRecovery(int $id, \App\Domains\Banking\Services\ProcessReceiptService $service): void
    {
        $service->undoRecovery($this->receipt(), $id);
        $this->dispatch('receipt-destination-updated');
    }

    public function undoAdvance(int $id, \App\Domains\Banking\Services\ProcessReceiptService $service): void
    {
        $service->undoAdvance($this->receipt(), $id);
        $this->dispatch('receipt-destination-updated');
    }

    public function clear(ReceiptDestinationService $service): void
    {
        try { $service->clear($this->receipt()); }
        catch (\DomainException $e) { $this->addError('division', $e->getMessage()); return; }
        $this->loadRows();
        $this->dispatch('receipt-destination-updated');
    }

    public function undoPayment(int $id, ReceiptDestinationService $service): void
    {
        $payment = \App\Domains\Banking\Models\ReceiptDestinationPayment::whereHas('destination', fn ($q) => $q->where('receipt_id', $this->receiptId))->findOrFail($id);
        $service->undo($payment);
        $this->dispatch('receipt-destination-updated');
    }

    public function undoBrokerPayment(int $id, BrokerLedgerDeletionService $service): void
    {
        $ids = $this->receipt()->destinations()->where('kind', 'broker')->pluck('commission_id');
        $payment = BrokerCommissionPayment::whereIn('commission_id', $ids)->findOrFail($id);
        $service->deletePayment($payment);
        $this->receipt()->touch();
        $this->dispatch('receipt-destination-updated');
    }

    public function close(): void
    {
        $this->dispatch('receipt-destination-closed');
    }

    public function with(): array
    {
        $receipt = $this->receipt();
        $parts = $receipt->destinations()->with('payments.transaction', 'commission.payments.transaction.destinationPayment', 'commission.broker', 'commission.caseType', 'commission.settlements.advance', 'recoveries.expense')->get();
        return [
            'receipt' => $receipt, 'parts' => $parts,
            'processReceipt' => $receipt->processReceipt,
            'recoveredAmount' => $parts->sum(fn ($part) => $part->recoveredCents()) / 100,
            'canEdit' => ! $parts->contains(fn ($part) => $part->paidCents() > 0 || $part->payments->isNotEmpty()),
            'ownAmount' => $parts->where('kind', 'own')->sum('amount'),
        ];
    }
}; ?>

<div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" x-data @keydown.escape.window="$wire.close()">
    <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="close" aria-label="Fechar recebimento"></button>
    <div role="dialog" aria-modal="true" aria-labelledby="receipt-destination-title" x-trap.inert.noscroll="true" class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-elevated">
        <div class="flex items-center justify-between border-b border-mono-100 px-6 py-4">
            <h3 id="receipt-destination-title" class="text-lg font-bold text-mono-900">Recebimento de processo</h3>
            <button type="button" wire:click="close" aria-label="Fechar"><span class="material-icons-outlined text-mono-400">close</span></button>
        </div>
        <div class="space-y-5 overflow-y-auto px-6 py-5">
            <div class="rounded-xl bg-mono-50 p-4 text-sm text-mono-900">
                <strong>{{ $receipt->description }}</strong> · {{ $receipt->bankAccount?->name }} · {{ $receipt->date->format('d/m/Y') }}
                <p class="mt-1">Entrada recebida: <strong>R$ {{ number_format((float) $receipt->amount, 2, ',', '.') }}</strong></p>
            </div>
            @error('division') <p class="text-sm font-medium text-error" role="alert">{{ $message }}</p> @enderror
            @if ($editing)
                <livewire:banking.transactions.process-receipt-form :receipt-id="$receiptId" :key="'process-form-'.$receiptId" />
            @else
                @if ($processReceipt)
                    <div class="rounded-xl bg-mono-50 p-4 text-sm text-mono-900">
                        <p>Corretor: <strong>{{ $processReceipt->broker->name }}</strong></p>
                        <p>Honorários: <strong>R$ {{ number_format((float) $processReceipt->fees_amount, 2, ',', '.') }}</strong> · Cliente bruto: <strong>R$ {{ number_format((float) $receipt->amount - (float) $processReceipt->fees_amount, 2, ',', '.') }}</strong></p>
                        <p>Despesas recuperadas: <strong>R$ {{ number_format($recoveredAmount, 2, ',', '.') }}</strong></p>
                        <p>Categoria da minha receita: <strong>{{ $processReceipt->category?->name ?? 'Sem categoria' }}</strong></p>
                    </div>
                @endif
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-xl bg-green-50 p-4 text-sm text-green-800">Minha receita<strong class="mt-1 block text-xl">R$ {{ number_format($ownAmount, 2, ',', '.') }}</strong></div>
                    <div class="rounded-xl bg-orange-50 p-4 text-sm text-orange-800">Cliente líquido<strong class="mt-1 block text-xl">R$ {{ number_format($parts->where('kind', 'client')->sum('amount') - $recoveredAmount, 2, ',', '.') }}</strong></div>
                    <div class="rounded-xl bg-blue-50 p-4 text-sm text-blue-800">Comissões de corretor e escritório<strong class="mt-1 block text-xl">R$ {{ number_format($parts->whereIn('kind', ['broker', 'office'])->sum('amount'), 2, ',', '.') }}</strong></div>
                </div>
                @foreach ($parts as $part)
                    <div class="space-y-3 rounded-xl border border-mono-100 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <div><strong class="text-mono-900">{{ ['client' => 'Cliente', 'broker' => 'Corretor', 'office' => 'Escritório', 'own' => 'Minha receita'][$part->kind] }}@if ($part->kind !== 'own') · {{ $part->beneficiary }}@endif</strong>
                                <p class="mt-1 text-mono-600">R$ {{ number_format((float) $part->amount, 2, ',', '.') }}</p>
                                @if ($part->kind === 'client' && $part->recoveredCents() > 0)<p class="mt-1 text-sm text-mono-900">Valor líquido do cliente: <strong>R$ {{ number_format((float) $part->amount - $part->recoveredCents() / 100, 2, ',', '.') }}</strong></p>@endif
                                @if ($part->commission)<p class="mt-1 text-sm text-mono-600">{{ $part->commission->caseType?->name }} · {{ $part->commission->name }}</p>@endif
                                @if ($part->commission?->broker?->contact_id)<a class="text-primary-600 hover:underline" href="{{ route('brokers.show', $part->commission->broker->contact_id) }}">Comissão #{{ $part->commission_id }} · Ver corretor</a>@endif
                            </div>

                        </div>
                        @foreach ($part->recoveries as $recovery)
                            <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-mono-50 p-3 text-xs text-mono-600"><span>Despesa recuperada · {{ $recovery->expense->description }} · R$ {{ number_format((float) $recovery->amount, 2, ',', '.') }}</span><button type="button" wire:click="undoRecovery({{ $recovery->id }})" wire:confirm="Desfazer este desconto? O valor voltará ao saldo do cliente e ficará disponível para recuperação." class="text-error">Desfazer desconto</button></div>
                        @endforeach
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
                            @foreach ($part->commission->settlements as $settlement)
                                <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-mono-50 p-3 text-xs text-mono-600"><span>Adiantamento compensado · {{ $settlement->advance?->date->format('d/m/Y') }} · R$ {{ number_format((float) $settlement->amount_offset, 2, ',', '.') }}</span><button type="button" wire:click="undoAdvance({{ $settlement->id }})" wire:confirm="Desfazer esta compensação?" class="text-error">Desfazer compensação</button></div>
                            @endforeach
                        @endif
                    </div>
                @endforeach
                @if ($canEdit)
                    <div class="flex flex-wrap gap-4"><button type="button" wire:click="editDivision" class="text-sm font-semibold text-primary-600">Editar divisão</button><button type="button" wire:click="clear" wire:confirm="Remover a divisão deste recebimento? Comissões criadas por ela e ainda sem repasses também serão removidas." class="text-sm text-error">Remover divisão</button></div>
                @else<p class="text-xs text-mono-600">Para editar a divisão, desfaça os descontos e compensações usados. Movimentações financeiras anteriores também precisam ser desvinculadas.</p>@endif
            @endif

        </div>
        <div class="flex justify-end border-t border-mono-100 px-6 py-4"><button type="button" wire:click="close" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900">Fechar</button></div>
    </div>
</div>

<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Services\ReceiptDestinationService;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $accountId;

    #[Locked]
    public string $paymentKey = '';

    public ?int $destinationId = null;
    public ?int $bankId = null;
    public string $amount = '';
    public string $date = '';

    public function mount(int $accountId): void
    {
        BankAccount::where('type', 'repasse')->findOrFail($accountId);
        $this->accountId = $accountId;
    }

    private function part(int $id): ReceiptDestination
    {
        return ReceiptDestination::where('repasse_account_id', $this->accountId)
            ->whereIn('kind', ['client', 'office'])->findOrFail($id);
    }

    public function startPayment(int $id): void
    {
        $part = $this->part($id);
        $this->paymentKey = (string) \Illuminate\Support\Str::uuid();
        $this->destinationId = $part->id;
        $this->bankId = BankAccount::active()->where('type', '!=', 'repasse')->find($part->receipt->bank_account_id)?->id;
        $this->amount = number_format($part->remainingCents() / 100, 2, '.', '');
        $this->date = now()->toDateString();
        $this->resetValidation();
    }

    public function cancelPayment(): void
    {
        $this->reset('destinationId', 'bankId', 'amount', 'date', 'paymentKey');
        $this->resetValidation();
    }

    public function savePayment(): void
    {
        $this->validate([
            'destinationId' => 'required|integer',
            'paymentKey' => 'required|uuid',
            'bankId' => 'required|integer',
            'amount' => 'required|string',
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
        ]);
        $part = $this->part($this->destinationId);
        try {
            app(ReceiptDestinationService::class)->pay($part, $this->amount, $this->date, $this->bankId, requestKey: $this->paymentKey);
        } catch (\DomainException $e) {
            $this->addError('payment', $e->getMessage());
            return;
        }
        $this->cancelPayment();
        session()->flash('repasse-status', 'Repasse registrado. O saldo a repassar foi reduzido.');
    }

    public function undoPayment(int $id): void
    {
        $payment = ReceiptDestinationPayment::whereHas('destination', fn ($q) => $q->where('repasse_account_id', $this->accountId)->whereIn('kind', ['client', 'office']))->findOrFail($id);
        app(ReceiptDestinationService::class)->undo($payment);
        $this->cancelPayment();
        session()->flash('repasse-status', 'Repasse desfeito. O valor voltou ao saldo a repassar.');
    }

    public function close(): void
    {
        $this->dispatch('repasses-closed');
    }

    public function with(): array
    {
        $account = BankAccount::where('type', 'repasse')->findOrFail($this->accountId);
        $parts = ReceiptDestination::with('receipt.bankAccount', 'receipt.destinations', 'recoveries', 'payments.transaction.bankAccount')
            ->where('repasse_account_id', $this->accountId)->whereIn('kind', ['client', 'office'])
            ->whereHas('receipt')->orderByDesc('id')->get();
        return [
            'account' => $account, 'parts' => $parts,
            'selectedPart' => $this->destinationId ? $this->part($this->destinationId) : null,
            'banks' => BankAccount::active()->where('type', '!=', 'repasse')->orderBy('name')->get(),
            'remaining' => $parts->sum(fn ($part) => $part->remainingCents()) / 100,
        ];
    }
}; ?>

<div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="repasses-title" x-on:keydown.escape.window="$wire.close()">
    <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="close" aria-label="Fechar repasses"></button>
    <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
        <div class="flex items-center justify-between gap-3 border-b border-mono-100 px-6 py-4">
            <h3 id="repasses-title" class="text-lg font-bold text-mono-900">Repasses · {{ $account->name }}</h3>
            <button type="button" wire:click="close" aria-label="Fechar" class="text-mono-600"><span class="material-icons-outlined">close</span></button>
        </div>
        <div class="space-y-5 overflow-y-auto p-6">
            <div class="rounded-xl bg-mono-50 p-4 text-sm text-mono-600">
                <p>Falta repassar: <strong class="text-lg text-mono-900">R$ {{ number_format($remaining, 2, ',', '.') }}</strong></p>
                <p class="mt-2">O saldo negativo desta conta representa valores de terceiros. Cada pagamento reduz o banco escolhido e aproxima o saldo desta conta de zero. Você pode pagar em partes.</p>
            </div>
            @if (session('repasse-status'))<p role="status" class="text-sm text-green-700">{{ session('repasse-status') }}</p>@endif
            @if ($selectedPart)
                <form wire:submit="savePayment" class="space-y-4 rounded-xl border border-primary-500 p-4" wire:key="payment-{{ $destinationId }}">
                    <h4 class="font-semibold text-mono-900">Registrar repasse · {{ $selectedPart->beneficiary }}</h4>
                    <p class="text-sm text-mono-600">{{ $selectedPart->receipt->description }} · Restante: R$ {{ number_format($selectedPart->remainingCents() / 100, 2, ',', '.') }}</p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <x-jr.input label="Valor do repasse (R$) *" name="amount" wire:model="amount" x-money required />
                        <x-jr.input label="Data do pagamento *" name="date" wire:model="date" type="date" max="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <label for="repasse-bank" class="mb-2 block text-sm font-medium text-mono-600">Pagar pela conta *</label>
                        <select id="repasse-bank" wire:model="bankId" required class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900">
                            <option value="">Selecione a conta bancária</option>
                            @foreach ($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->name }}</option>@endforeach
                        </select>
                        @error('bankId')<p role="alert" class="mt-2 text-sm text-error">{{ $message }}</p>@enderror
                    </div>
                    @error('payment')<p role="alert" class="text-sm text-error">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" wire:click="cancelPayment" class="rounded-pill bg-mono-100 px-5 py-3 text-sm font-semibold text-mono-900">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="savePayment" class="rounded-pill bg-primary-500 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Confirmar pagamento</button>
                    </div>
                </form>
            @endif
            @forelse ($parts as $part)
                <div class="space-y-3 rounded-xl border border-mono-100 p-4" wire:key="part-{{ $part->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-mono-900">{{ $part->beneficiary }}</h4>
                            <p class="text-sm text-mono-600">{{ $part->kind === 'office' ? $part->receipt->destinations->firstWhere('kind', 'client')?->beneficiary.' · ' : '' }}{{ $part->receipt->description }} · {{ $part->receipt->bankAccount?->name }} · {{ $part->receipt->date->format('d/m/Y') }}</p>
                            <p class="mt-2 text-sm text-mono-900">Valor a repassar: R$ {{ number_format(((float) $part->amount) - $part->recoveredCents() / 100, 2, ',', '.') }} · Restante: <strong>R$ {{ number_format($part->remainingCents() / 100, 2, ',', '.') }}</strong></p>
                        </div>
                        @if ($part->remainingCents() > 0)
                            <button type="button" wire:click="startPayment({{ $part->id }})" class="text-sm font-semibold text-primary-600">Registrar repasse</button>
                        @else<span class="rounded-pill bg-green-50 px-3 py-1 text-sm text-green-800">Repassado</span>@endif
                    </div>
                    @foreach ($part->payments as $payment)
                        <div class="flex flex-wrap justify-between gap-2 rounded-lg bg-mono-50 p-3 text-sm text-mono-600">
                            <span>{{ $payment->transaction?->date->format('d/m/Y') }} · R$ {{ number_format(abs((float) $payment->transaction?->amount), 2, ',', '.') }} · {{ $payment->transaction?->bankAccount?->name }}</span>
                            <button type="button" wire:click="undoPayment({{ $payment->id }})" wire:confirm="{{ $payment->transaction_created ? 'Desfazer este pagamento? O banco e o saldo a repassar serão restaurados.' : 'Desvincular este pagamento? A saída original será preservada e o saldo a repassar será restaurado.' }}" class="font-semibold text-error">{{ $payment->transaction_created ? 'Desfazer repasse' : 'Desvincular pagamento' }}</button>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-mono-600">Nenhum recebimento vinculado a esta conta.</p>
            @endforelse
        </div>
    </div>
</div>

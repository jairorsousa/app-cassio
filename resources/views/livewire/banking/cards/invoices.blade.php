<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Services\InvoicePaymentService;
use App\Domains\Banking\Services\InvoiceService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public CreditCard $card;

    public ?int $payingInvoiceId = null;
    public ?int $payment_account_id = null;
    public string $payment_amount = '';
    public string $payment_date = '';
    public string $payment_notes = '';

    public ?int $expandedInvoiceId = null;

    public function mount(CreditCard $card): void
    {
        $this->card = $card;
        $this->payment_account_id = $card->default_payment_account_id;
        $this->payment_date = \App\Support\BusinessDate::today();
    }

    public function toggle(int $invoiceId): void
    {
        $this->expandedInvoiceId = $this->expandedInvoiceId === $invoiceId ? null : $invoiceId;
    }

    public function startPayment(int $invoiceId): void
    {
        $invoice = $this->card->invoices()->findOrFail($invoiceId);
        if (! $invoice->isPayable()) {
            session()->flash('error', 'Esta fatura já está quitada.');

            return;
        }
        $this->payingInvoiceId = $invoice->id;
        $this->payment_amount = number_format($invoice->remainingAmount(), 2, '.', '');
        $this->payment_account_id = $this->payment_account_id ?: $this->card->default_payment_account_id;
        $this->payment_date = \App\Support\BusinessDate::today();
        $this->payment_notes = '';
        $this->resetValidation();
    }

    public function cancelPayment(): void
    {
        $this->payingInvoiceId = null;
        $this->resetValidation();
    }

    public function pay(InvoicePaymentService $service): void
    {
        $this->payment_amount = str_contains($this->payment_amount, ',')
            ? str_replace(['.', ','], ['', '.'], $this->payment_amount)
            : $this->payment_amount;
        $data = $this->validate([
            'payment_account_id' => 'required|exists:bank_accounts,id',
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date|before_or_equal:'.\App\Support\BusinessDate::today(),
            'payment_notes' => 'nullable|string|max:500',
        ], [
            'payment_account_id.required' => 'Escolha a conta de onde saiu o pagamento.',
            'payment_amount.min' => 'Informe um valor maior que zero.',
            'payment_date.before_or_equal' => 'A data do pagamento não pode ser futura.',
        ]);

        $invoice = $this->card->invoices()->findOrFail($this->payingInvoiceId);
        $account = BankAccount::findOrFail($data['payment_account_id']);

        try {
            $service->pay($invoice, $account, (float) $data['payment_amount'], $data['payment_date'], $data['payment_notes'] ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->addError('payment_amount', $e->getMessage());

            return;
        }

        $fresh = $invoice->fresh();
        $this->payingInvoiceId = null;
        $this->payment_amount = '';
        session()->flash('status', $fresh->displayStatus() === 'paid'
            ? 'Pagamento registrado. Fatura '.$fresh->reference_month.' quitada.'
            : 'Pagamento parcial registrado. Restam R$ '.number_format($fresh->remainingAmount(), 2, ',', '.').'.');
    }

    public function close(int $invoiceId, InvoiceService $service): void
    {
        $invoice = $this->card->invoices()->findOrFail($invoiceId);
        $service->closeInvoice($invoice);
        session()->flash('status', 'Fatura fechada.');
    }

    public function with(): array
    {
        $invoices = $this->card->invoices()
            ->with(['transactions' => fn ($q) => $q->with(['category', 'bankAccount'])->orderBy('date')->orderBy('id')])
            ->orderByDesc('reference_month')->get();

        return [
            'invoices' => $invoices,
            'paying' => $this->payingInvoiceId ? $invoices->firstWhere('id', $this->payingInvoiceId) : null,
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'summary' => [
                'due' => $invoices->filter(fn ($i) => $i->status !== 'open')->sum(fn ($i) => $i->remainingAmount()),
                'overdue' => $invoices->filter(fn ($i) => $i->isOverdue())->sum(fn ($i) => $i->remainingAmount()),
                'open' => $invoices->where('status', 'open')->sum(fn ($i) => $i->remainingAmount()),
            ],
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

@php
    $badge = ['open' => 'bg-blue-50 text-blue-700', 'closed' => 'bg-amber-50 text-amber-700', 'partially_paid' => 'bg-orange-50 text-orange-700', 'paid' => 'bg-green-50 text-green-700', 'overdue' => 'bg-red-50 text-red-700'];
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
@endphp

<div class="flex flex-col gap-md">
    <x-banking.subnav />
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif
    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">{{ $card->name }}</h3>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-mono-600">
                    <span>Titular: <strong class="{{ $card->hasHolder() ? 'text-mono-900' : 'text-amber-700' }}">{{ $card->holderLabel() }}</strong></span>
                    <span>Fecha dia {{ $card->closing_day }} · vence dia {{ $card->due_day }}</span>
                    @if ($card->defaultPaymentAccount)<span>Conta padrão: {{ $card->defaultPaymentAccount->name }}</span>@endif
                </div>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs" data-testid="invoice-summary">
                <div class="rounded-xl bg-mono-50 px-3 py-2"><div class="text-mono-600">A pagar (fechadas)</div><div class="font-semibold text-mono-900">{{ $brl($summary['due']) }}</div></div>
                <div class="rounded-xl bg-red-50 px-3 py-2"><div class="text-red-700">Vencido</div><div class="font-semibold text-red-700">{{ $brl($summary['overdue']) }}</div></div>
                <div class="rounded-xl bg-blue-50 px-3 py-2"><div class="text-blue-700">Em aberto</div><div class="font-semibold text-blue-700">{{ $brl($summary['open']) }}</div></div>
            </div>
        </div>
    </x-fx.card>

    <x-fx.card>
        @if ($invoices->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma fatura gerada ainda. Lance uma compra no cartão para abrir uma fatura.</div>
        @else
            <div class="flex flex-col divide-y divide-mono-100">
                @foreach ($invoices as $inv)
                    @php $st = $inv->displayStatus(); $purchases = $inv->transactions->where('type', 'expense'); $payments = $inv->transactions->where('type', 'invoice_payment'); @endphp
                    <div class="py-3" wire:key="inv-{{ $inv->id }}">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div class="flex items-center gap-3">
                                <button type="button" class="text-mono-400 hover:text-mono-700" wire:click="toggle({{ $inv->id }})" aria-label="Ver compras e pagamentos">
                                    <span class="material-icons-outlined text-[20px]">{{ $expandedInvoiceId === $inv->id ? 'expand_less' : 'expand_more' }}</span>
                                </button>
                                <div>
                                    <div class="font-semibold text-mono-900">Fatura {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $inv->reference_month)->translatedFormat('M/Y') }}</div>
                                    <div class="text-xxs text-mono-600">Fecha {{ $inv->closing_date->format('d/m/Y') }} · vence {{ $inv->due_date->format('d/m/Y') }}</div>
                                </div>
                                <span class="rounded-pill px-2 py-0.5 text-xxs font-semibold {{ $badge[$st] }}">{{ \App\Domains\Banking\Models\CreditCardInvoice::STATUS_LABELS[$st] }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-3 text-right text-xs md:w-[360px]">
                                <div><div class="text-mono-500">Total</div><div class="font-medium">{{ $brl($inv->total) }}</div></div>
                                <div><div class="text-mono-500">Pago</div><div class="font-medium text-green-700">{{ $brl($inv->paid_amount) }}</div></div>
                                <div><div class="text-mono-500">Restante</div><div class="font-semibold">{{ $brl($inv->remainingAmount()) }}</div></div>
                            </div>
                            <div class="flex gap-2 md:justify-end">
                                @if ($inv->status === 'open')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="close({{ $inv->id }})" wire:confirm="Fechar a fatura {{ $inv->reference_month }}? Novas compras irão para a próxima.">Fechar</button>
                                @endif
                                @if ($inv->isPayable())
                                    <button class="fx-btn fx-btn--primary fx-btn--sm" wire:click="startPayment({{ $inv->id }})">{{ $inv->status === 'open' ? 'Antecipar' : 'Pagar' }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($expandedInvoiceId === $inv->id)
                            <div class="mt-3 grid grid-cols-1 gap-4 rounded-xl bg-mono-50 p-3 text-xs md:grid-cols-2">
                                <div>
                                    <div class="mb-1 font-semibold text-mono-700">Compras ({{ $purchases->count() }})</div>
                                    @forelse ($purchases as $t)
                                        <div class="flex justify-between gap-2 py-0.5">
                                            <span>{{ $t->date->format('d/m') }} · {{ $t->description }}@if ($t->installment_total) <span class="text-mono-500">({{ $t->installment_number }}/{{ $t->installment_total }})</span>@endif @if ($t->category)<span class="text-mono-500">· {{ $t->category->name }}</span>@endif</span>
                                            <span class="whitespace-nowrap">{{ $brl($t->amount) }}</span>
                                        </div>
                                    @empty
                                        <div class="text-mono-500">Sem compras nesta fatura.</div>
                                    @endforelse
                                </div>
                                <div>
                                    <div class="mb-1 font-semibold text-mono-700">Pagamentos ({{ $payments->count() }})</div>
                                    @forelse ($payments as $p)
                                        <div class="flex justify-between gap-2 py-0.5">
                                            <span>{{ $p->date->format('d/m/Y') }} · {{ $p->bankAccount?->name ?? 'Conta' }}@if ($p->notes) <span class="text-mono-500">· {{ $p->notes }}</span>@endif</span>
                                            <span class="whitespace-nowrap text-green-700">{{ $brl($p->amount) }}</span>
                                        </div>
                                    @empty
                                        <div class="text-mono-500">Nenhum pagamento registrado.</div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-fx.card>

    @if ($paying)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelPayment" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[60px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">Pagar fatura {{ $paying->reference_month }}</h3>
                    <button type="button" class="text-mono-400" wire:click="cancelPayment" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <form wire:submit="pay" class="space-y-4 px-6 py-5">
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-mono-50 p-3 text-center text-xs">
                        <div><div class="text-mono-500">Total</div><div class="font-semibold">{{ $brl($paying->total) }}</div></div>
                        <div><div class="text-mono-500">Já pago</div><div class="font-semibold">{{ $brl($paying->paid_amount) }}</div></div>
                        <div><div class="text-mono-500">Restante</div><div class="font-semibold">{{ $brl($paying->remainingAmount()) }}</div></div>
                    </div>
                    <x-banking.account-picker :accounts="$accounts" :selected-id="$payment_account_id" model="payment_account_id" id="invoice-payment-account" label="Conta de pagamento *" />
                    @error('payment_account_id') <p class="text-xs font-medium text-error">{{ $message }}</p> @enderror
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-fx.input label="Valor * (total ou parcial)" type="text" wire:model="payment_amount" />
                            @error('payment_amount') <p class="mt-1 text-xs font-medium text-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-fx.input label="Data do pagamento *" type="date" wire:model="payment_date" />
                            @error('payment_date') <p class="mt-1 text-xs font-medium text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <x-fx.input label="Observação" type="text" wire:model="payment_notes" />
                    <p class="text-xxs text-mono-500">O pagamento sai da conta escolhida como quitação da fatura. As compras já foram lançadas no cartão e não são duplicadas.</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold" wire:click="cancelPayment">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Confirmar pagamento</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <a href="{{ route('banking.cards.index') }}" class="fx-btn fx-btn--text fx-btn--sm self-start">← Voltar</a>
</div>

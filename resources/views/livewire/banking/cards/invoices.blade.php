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

    public function mount(CreditCard $card): void
    {
        $this->card = $card;
        $this->payment_account_id = $card->default_payment_account_id;
        $this->payment_date = now()->format('Y-m-d');
    }

    public function startPayment(int $invoiceId): void
    {
        $invoice = CreditCardInvoice::findOrFail($invoiceId);
        $this->payingInvoiceId = $invoice->id;
        $this->payment_amount = (string) $invoice->remainingAmount();
    }

    public function pay(InvoicePaymentService $service): void
    {
        $data = $this->validate([
            'payment_account_id' => 'required|exists:bank_accounts,id',
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
        ]);

        $invoice = CreditCardInvoice::findOrFail($this->payingInvoiceId);
        $account = BankAccount::findOrFail($data['payment_account_id']);

        $service->pay($invoice, $account, (float) $data['payment_amount'], $data['payment_date']);

        $this->payingInvoiceId = null;
        $this->payment_amount = '';
        session()->flash('status', 'Pagamento registrado.');
    }

    public function close(int $invoiceId, InvoiceService $service): void
    {
        $invoice = CreditCardInvoice::findOrFail($invoiceId);
        $service->closeInvoice($invoice);
        session()->flash('status', 'Fatura fechada.');
    }

    public function with(): array
    {
        return [
            'invoices' => $this->card->invoices()->orderByDesc('reference_month')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Cartão · {{ $card->name }} · Faturas</x-slot>

<div class="flex flex-col gap-md">
    <x-fx.card>
        @if ($invoices->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma fatura gerada ainda. Lance uma compra no cartão para abrir uma fatura.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Referência</th>
                        <th class="text-left">Status</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Pago</th>
                        <th class="text-right">Restante</th>
                        <th class="text-center">Vencimento</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoices as $inv)
                        <tr>
                            <td>{{ $inv->reference_month }}</td>
                            <td>
                                <span class="fx-badge">{{ ['open'=>'Aberta','closed'=>'Fechada','partially_paid'=>'Parcial','paid'=>'Paga'][$inv->status] }}</span>
                            </td>
                            <td class="text-right">R$ {{ number_format($inv->total, 2, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format($inv->paid_amount, 2, ',', '.') }}</td>
                            <td class="text-right font-semibold">R$ {{ number_format($inv->remainingAmount(), 2, ',', '.') }}</td>
                            <td class="text-center text-xxs">{{ $inv->due_date->format('d/m/Y') }}</td>
                            <td class="text-right">
                                @if ($inv->status === 'open')
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="close({{ $inv->id }})">Fechar</button>
                                @endif
                                @if ($inv->isOpen() && $inv->total > 0)
                                    <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="startPayment({{ $inv->id }})">Pagar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    @if ($payingInvoiceId)
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Pagar fatura</h3>
            <form wire:submit="pay" class="grid grid-cols-1 md:grid-cols-4 gap-sm items-end">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Conta</label>
                    <select wire:model="payment_account_id" class="fx-form-field">
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-fx.input label="Valor" type="text" x-money wire:model="payment_amount" />
                <x-fx.input label="Data" type="date" wire:model="payment_date" />
                <div class="flex gap-xs">
                    <button type="submit" class="fx-btn fx-btn--primary">Confirmar</button>
                    <button type="button" class="fx-btn fx-btn--text" wire:click="$set('payingInvoiceId', null)">Cancelar</button>
                </div>
            </form>
        </x-fx.card>
    @endif

    <a href="{{ route('banking.cards.index') }}" class="fx-btn fx-btn--text fx-btn--sm self-start">← Voltar</a>
</div>

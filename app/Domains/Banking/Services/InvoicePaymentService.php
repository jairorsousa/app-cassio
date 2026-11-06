<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pagamento de fatura = quitação (type invoice_payment), nunca uma nova despesa.
 * Sai da conta bancária escolhida (saldo/caixa); as compras já foram lançadas uma única vez no cartão.
 * Regime de caixa: a despesa aparece pelo pagamento. Competência: pela data da compra (pagamento não conta).
 */
class InvoicePaymentService
{
    public function __construct(private InvoiceService $invoices) {}

    public function pay(
        CreditCardInvoice $invoice,
        BankAccount $account,
        float $amount,
        Carbon|string $date,
        ?string $notes = null,
    ): Transaction {
        $cents = (int) round($amount * 100);
        if ($cents <= 0) {
            throw new \InvalidArgumentException('Valor do pagamento deve ser positivo.');
        }
        if (! BankAccount::whereKey($account->id)->value('status')) {
            throw new \InvalidArgumentException('Escolha uma conta ativa para pagar a fatura.');
        }

        return DB::transaction(function () use ($invoice, $account, $cents, $date, $notes) {
            $invoice = CreditCardInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice = $this->invoices->syncPayments($invoice);
            $remaining = (int) round($invoice->remainingAmount() * 100);
            if ($remaining <= 0) {
                throw new \InvalidArgumentException('Esta fatura já está quitada.');
            }
            if ($cents > $remaining) {
                throw new \InvalidArgumentException('O valor informado é maior que o restante da fatura (R$ '.number_format($remaining / 100, 2, ',', '.').').');
            }

            $payment = Transaction::create([
                'type' => 'invoice_payment',
                'date' => $date,
                'amount' => $cents / 100,
                'description' => "Pagamento fatura {$invoice->reference_month} - {$invoice->creditCard->name}",
                'notes' => $notes,
                'status' => 'settled',
                'bank_account_id' => $account->id,
                'credit_card_id' => $invoice->credit_card_id,
                'credit_card_invoice_id' => $invoice->id,
            ]);

            $this->invoices->syncPayments($invoice);

            return $payment;
        });
    }
}

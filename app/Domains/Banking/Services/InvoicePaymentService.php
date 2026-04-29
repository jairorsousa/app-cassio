<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoicePaymentService
{
    public function pay(
        CreditCardInvoice $invoice,
        BankAccount $account,
        float $amount,
        Carbon|string $date,
        ?string $notes = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor do pagamento deve ser positivo.');
        }

        return DB::transaction(function () use ($invoice, $account, $amount, $date, $notes) {
            $payment = Transaction::create([
                'type' => 'invoice_payment',
                'date' => $date,
                'amount' => $amount,
                'description' => "Pagamento fatura {$invoice->reference_month} - {$invoice->creditCard->name}",
                'notes' => $notes,
                'status' => 'settled',
                'bank_account_id' => $account->id,
                'credit_card_id' => $invoice->credit_card_id,
                'credit_card_invoice_id' => $invoice->id,
            ]);

            $newPaid = (float) $invoice->paid_amount + $amount;
            $status = $newPaid >= (float) $invoice->total ? 'paid' : 'partially_paid';

            $invoice->update([
                'paid_amount' => $newPaid,
                'status' => $status,
            ]);

            return $payment;
        });
    }
}

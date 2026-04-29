<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallmentService
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * @return Transaction[]
     */
    public function split(
        CreditCard $card,
        Carbon $purchaseDate,
        float $totalAmount,
        int $installments,
        string $description,
        ?int $categoryId = null,
        ?string $notes = null,
    ): array {
        if ($installments < 1) {
            throw new \InvalidArgumentException('Número de parcelas inválido.');
        }
        if ($totalAmount <= 0) {
            throw new \InvalidArgumentException('Valor total deve ser positivo.');
        }

        return DB::transaction(function () use ($card, $purchaseDate, $totalAmount, $installments, $description, $categoryId, $notes) {
            $groupId = (string) Str::uuid();
            $perInstallment = round($totalAmount / $installments, 2);
            $remainder = round($totalAmount - ($perInstallment * $installments), 2);

            $created = [];

            for ($i = 1; $i <= $installments; $i++) {
                $amount = $perInstallment;
                if ($i === $installments) {
                    $amount = round($amount + $remainder, 2);
                }

                $monthOffset = $i - 1;
                $invoiceDate = $purchaseDate->copy()->addMonthsNoOverflow($monthOffset);

                $invoice = $this->invoiceService->findOrCreateForPurchase($card, $invoiceDate);

                $created[] = Transaction::create([
                    'type' => 'expense',
                    'date' => $invoiceDate,
                    'amount' => $amount,
                    'description' => $installments > 1
                        ? "{$description} ({$i}/{$installments})"
                        : $description,
                    'notes' => $notes,
                    'status' => 'settled',
                    'category_id' => $categoryId,
                    'credit_card_id' => $card->id,
                    'credit_card_invoice_id' => $invoice->id,
                    'installment_group_id' => $installments > 1 ? $groupId : null,
                    'installment_number' => $installments > 1 ? $i : null,
                    'installment_total' => $installments > 1 ? $installments : null,
                ]);

                $this->invoiceService->recalculateTotal($invoice);
            }

            return $created;
        });
    }
}

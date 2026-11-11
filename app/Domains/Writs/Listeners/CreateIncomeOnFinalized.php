<?php

namespace App\Domains\Writs\Listeners;

use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Writs\Events\WritMovedToFinalized;

class CreateIncomeOnFinalized
{
    public function __construct(private TransactionService $transactions)
    {
    }

    public function handle(WritMovedToFinalized $event): void
    {
        $writ = $event->writ;

        if ($writ->actual_receipt_amount === null || (float) $writ->actual_receipt_amount <= 0) {
            return;
        }

        $existing = $writ->transactions()->where('type', 'income')->exists();
        if ($existing) {
            return;
        }

        // Marca Juros Ganho no lancamento; o relatorio de fluxo separa lucro x retorno de capital
        // para nao tratar o custo como juros e sem criar outro credito no banco.
        $this->transactions->create([
            'type' => 'income',
            'date' => $writ->finalized_at ?? \App\Support\BusinessDate::today(),
            'amount' => $writ->actual_receipt_amount,
            'description' => 'Recebimento requisitório · '.($writ->process_number ?: '#'.$writ->id),
            'status' => 'settled',
            'bank_account_id' => $writ->destination_bank_account_id,
            'category_id' => Category::writProfitCategoryId(),
            'source_type' => $writ::class,
            'source_id' => $writ->id,
        ]);
    }
}

<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\StatementImportBatch;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoicePurchaseService
{
    public function __construct(private InvoiceService $invoices) {}

    public function assertCardPurchase(Transaction $tx, CreditCard $card): void
    {
        if ($tx->type !== 'expense') {
            throw new \InvalidArgumentException('Esta compra não pertence a este cartão.');
        }
        $ids = collect([$card->id])->merge($card->additionals()->pluck('id'))->map(fn ($id) => (int) $id)->all();
        if (! in_array((int) $tx->credit_card_id, $ids, true) && ! in_array((int) ($tx->additional_card_id ?: 0), $ids, true)) {
            throw new \InvalidArgumentException('Esta compra não pertence a este cartão.');
        }
    }

    public function requireOpenForChange(CreditCardInvoice $invoice, string $action = 'alterar as compras'): void
    {
        if ($invoice->transactions()->where('type', 'invoice_payment')->exists() && ! $invoice->isCycleOpen() && $invoice->displayStatus() === 'paid') {
            throw new \InvalidArgumentException('Esta fatura está paga. Estorne o pagamento ou reabra a fatura antes de '.$action.'.');
        }
    }

    public function destinationFor(CreditCard $card, string $referenceMonth, bool $create = true): CreditCardInvoice
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $referenceMonth)) {
            throw new \InvalidArgumentException('Informe a fatura de destino (mês).');
        }
        $ref = Carbon::createFromFormat('!Y-m', $referenceMonth);
        $invoice = CreditCardInvoice::where('credit_card_id', $card->id)->where('reference_month', $referenceMonth)->first();
        if (! $invoice) {
            if (! $create) {
                throw new \InvalidArgumentException('A fatura '.$referenceMonth.' ainda não existe.');
            }
            $invoice = $this->invoices->findOrCreateForReference($card, $ref);
        }
        if (! $invoice->isCycleOpen() && $invoice->displayStatus() === 'paid') {
            throw new \InvalidArgumentException('A fatura '.$referenceMonth.' está paga. Reabra-a antes de mover compras para ela.');
        }
        if (in_array($invoice->status, ['closed', 'partially_paid', 'paid'], true) && $invoice->closed_at) {
            throw new \InvalidArgumentException('A fatura '.$referenceMonth.' está fechada. Reabra-a antes de mover compras para ela.');
        }

        return $invoice;
    }

    public function updatePurchase(Transaction $tx, CreditCard $card, array $data): Transaction
    {
        return DB::transaction(function () use ($tx, $card, $data) {
            $tx = Transaction::query()->lockForUpdate()->findOrFail($tx->id);
            $this->assertCardPurchase($tx, $card);
            $from = $tx->invoice;
            if ($from) {
                $this->requireOpenForChange($from, 'editar esta compra');
            }
            $date = Carbon::parse($data['date']);
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Informe um valor maior que zero.');
            }
            $destMonth = $data['invoice_month'] ?? ($from?->reference_month ?: $this->invoices->referenceMonthForPurchase($card, $date));
            $dest = $this->destinationFor($card, $destMonth);
            $tx->update([
                'date' => $date->format('Y-m-d'),
                'description' => mb_substr(trim((string) $data['description']) ?: $tx->description, 0, 255),
                'amount' => number_format($amount, 2, '.', ''),
                'category_id' => $data['category_id'] ?? $tx->category_id,
                'credit_card_invoice_id' => $dest->id,
            ]);
            $this->invoices->recalculateTotal($dest->fresh());
            if ($from && $from->id !== $dest->id) {
                $this->invoices->recalculateTotal($from->fresh());
            }

            return $tx->fresh();
        });
    }

    public function movePurchases(CreditCard $card, array $transactionIds, string $referenceMonth): int
    {
        return DB::transaction(function () use ($card, $transactionIds, $referenceMonth) {
            $dest = $this->destinationFor($card, $referenceMonth);
            $moved = 0;
            $touched = [$dest->id => $dest];
            foreach ($transactionIds as $id) {
                $tx = Transaction::query()->lockForUpdate()->find($id);
                if (! $tx) {
                    continue;
                }
                $this->assertCardPurchase($tx, $card);
                $from = $tx->invoice;
                if ($from) {
                    $this->requireOpenForChange($from, 'mover esta compra');
                    $touched[$from->id] = $from;
                }
                if ((int) $tx->credit_card_invoice_id === (int) $dest->id) {
                    continue;
                }
                $tx->update(['credit_card_invoice_id' => $dest->id]);
                $moved++;
            }
            foreach ($touched as $inv) {
                $this->invoices->recalculateTotal($inv->fresh());
            }

            return $moved;
        });
    }

    public function deletePurchases(CreditCard $card, array $transactionIds): int
    {
        return DB::transaction(function () use ($card, $transactionIds) {
            $deleted = 0;
            $touched = [];
            foreach ($transactionIds as $id) {
                $tx = Transaction::query()->lockForUpdate()->find($id);
                if (! $tx) {
                    continue;
                }
                $this->assertCardPurchase($tx, $card);
                $from = $tx->invoice;
                if ($from) {
                    $this->requireOpenForChange($from, 'excluir esta compra');
                    $touched[$from->id] = $from;
                }
                $tx->delete();
                $deleted++;
            }
            foreach ($touched as $inv) {
                $this->invoices->recalculateTotal($inv->fresh());
            }

            return $deleted;
        });
    }

    /**
     * Importações que podem ser desfeitas neste cartão: lotes formais e grupos antigos (sem lote),
     * agrupados pela hora em que foram gravados.
     *
     * @return list<array<string, mixed>>
     */
    public function listImports(CreditCard $card): array
    {
        $out = [];
        $batches = StatementImportBatch::query()
            ->where('credit_card_id', $card->id)
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->get();
        foreach ($batches as $b) {
            $txs = $b->transactions()->orderBy('date')->orderBy('id')->get();
            if ($txs->isEmpty()) {
                continue;
            }
            $out[] = $this->describeGroup('batch:'.$b->id, $b->filename ?: $b->source_label ?: 'Importação', $b->imported_at, $txs, $b->source_label);
        }

        $legacy = Transaction::query()
            ->where('credit_card_id', $card->id)
            ->whereNotNull('ofx_fitid')
            ->whereNull('import_batch_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->created_at?->format('Y-m-d H:i:s') ?: 'sem-data');
        foreach ($legacy as $ts => $txs) {
            $label = $txs->first()->notes ?: 'Importação antiga';
            if (preg_match('/Importado de extrato \((.+)\)/', (string) $label, $m)) {
                $label = 'Extrato ('.$m[1].')';
            }
            $out[] = $this->describeGroup('legacy:'.$ts, $label, $txs->first()->created_at, $txs, 'legado');
        }

        usort($out, fn ($a, $b) => strcmp($b['imported_at'] ?? '', $a['imported_at'] ?? ''));

        return $out;
    }

    public function undoImport(CreditCard $card, string $key): int
    {
        return DB::transaction(function () use ($card, $key) {
            $group = collect($this->listImports($card))->firstWhere('key', $key);
            if (! $group) {
                throw new \InvalidArgumentException('Não encontrei essa importação.');
            }
            $ids = $group['transaction_ids'];

            return $this->deletePurchases($card, $ids);
        });
    }

    private function describeGroup(string $key, string $label, mixed $when, Collection $txs, ?string $source): array
    {
        $byInvoice = $txs->groupBy(fn ($t) => $t->invoice?->reference_month ?? 'sem fatura')
            ->map(fn ($g) => ['count' => $g->count(), 'total' => round((float) $g->sum('amount'), 2)])
            ->all();

        return [
            'key' => $key,
            'label' => $label,
            'source' => $source,
            'imported_at' => $when instanceof \DateTimeInterface ? $when->copy()->timezone('UTC')->format('c') : (string) $when,
            'imported_at_label' => $when instanceof \DateTimeInterface ? $when->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : (string) $when,
            'items_count' => $txs->count(),
            'total_amount' => round((float) $txs->sum('amount'), 2),
            'transaction_ids' => $txs->pluck('id')->all(),
            'preview' => $txs->take(20)->map(fn ($t) => [
                'id' => $t->id,
                'date' => $t->date?->format('Y-m-d'),
                'description' => $t->description,
                'amount' => (float) $t->amount,
                'invoice' => $t->invoice?->reference_month,
            ])->all(),
            'by_invoice' => $byInvoice,
        ];
    }
}

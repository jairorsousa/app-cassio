<?php

namespace App\Domains\Assets\Services;

use App\Domains\Assets\Models\Property;
use App\Domains\Assets\Models\PropertyPayment;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\TransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pagamentos de bens. Pago pela conta → um lançamento de saída na conta (extrato);
 * pago no cartão → compra parcelada nas faturas. Previsto não mexe em saldo nem fatura.
 * Todo lançamento gerado fica ligado ao pagamento (source_type/source_id) e é somente leitura no Financeiro.
 */
class PropertyPaymentService
{
    public function __construct(private TransactionService $transactions, private InstallmentService $installments, private InvoiceService $invoices) {}

    /**
     * @param  array{status:string,date?:?string,amount:string|float,method?:?string,bank_account_id?:?int,credit_card_id?:?int,installments?:?int,category_id?:?int,notes?:?string}  $data
     */
    public function record(Property $property, array $data): PropertyPayment
    {
        $data = $this->clean($data);

        return DB::transaction(function () use ($property, $data) {
            $payment = $property->payments()->create($data + ['created_by' => auth()->id()]);
            if ($payment->status === 'paid') {
                $this->generate($payment);
            }
            $this->invalidate();

            return $payment->fresh(['transactions']);
        });
    }

    /** "Paguei": o previsto vira pago com a forma escolhida agora (conta, ou cartão com data e parcelas). */
    public function markPaid(PropertyPayment $payment, array $data): PropertyPayment
    {
        if (! $payment->isPlanned()) {
            throw new \DomainException('Este pagamento já está pago.');
        }
        $data = $this->clean(['status' => 'paid'] + $data);

        return DB::transaction(function () use ($payment, $data) {
            $payment->update($data);
            $this->generate($payment->fresh());
            $this->invalidate();

            return $payment->fresh(['transactions']);
        });
    }

    /** Apagar o pagamento apaga o lançamento (ou as parcelas no cartão) e recalcula as faturas. */
    public function delete(PropertyPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoiceIds = [];
            foreach ($payment->transactions()->get() as $t) {
                if ($t->credit_card_invoice_id) {
                    $invoiceIds[] = $t->credit_card_invoice_id;
                }
                $this->transactions->deleteGenerated($t);
            }
            foreach (CreditCardInvoice::whereIn('id', array_unique($invoiceIds))->get() as $invoice) {
                $this->invoices->recalculateTotal($invoice);
            }
            $payment->delete();
            $this->invalidate();
        });
    }

    /** Lançamentos já existentes na conta com o mesmo valor e data próxima (ex.: vindos de extrato importado). */
    public function possibleDuplicates(int $accountId, float $amount, string $date): Collection
    {
        $day = Carbon::parse($date);

        return Transaction::where('bank_account_id', $accountId)
            ->whereIn('type', ['expense', 'transfer'])
            ->whereBetween('date', [$day->copy()->subDays(3)->startOfDay(), $day->copy()->addDays(3)->endOfDay()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', PropertyPayment::class))
            ->get(['id', 'date', 'amount', 'description', 'type'])
            ->filter(fn ($t) => (int) round(abs((float) $t->amount) * 100) === (int) round($amount * 100))
            ->values();
    }

    /** Quanto ficaria somado (pago + previsto) com este valor, para avisar quando passa do total da compra. */
    public function exceedsTotal(Property $property, float $amount, ?int $ignorePaymentId = null): ?float
    {
        $total = (int) round((float) ($property->acquisition_value ?? 0) * 100);
        if ($total <= 0) {
            return null;
        }
        $others = (int) $property->payments()->when($ignorePaymentId, fn ($q) => $q->whereKeyNot($ignorePaymentId))->get()
            ->sum(fn ($p) => (int) round((float) $p->amount * 100));
        $sum = $others + (int) round($amount * 100);

        return $sum > $total ? ($sum - $total) / 100 : null;
    }

    private function clean(array $data): array
    {
        $status = in_array($data['status'] ?? null, ['paid', 'planned'], true) ? $data['status'] : throw new \DomainException('Escolha se o pagamento já foi feito ou é previsto.');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \DomainException('Informe o valor do pagamento.');
        }
        $method = in_array($data['method'] ?? null, ['account', 'card'], true) ? $data['method'] : null;
        $date = ! empty($data['date']) ? Carbon::parse($data['date'])->toDateString() : null;
        $accountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $cardId = ! empty($data['credit_card_id']) ? (int) $data['credit_card_id'] : null;
        $installments = max(1, min(48, (int) ($data['installments'] ?? 1)));
        $categoryId = ! empty($data['category_id']) ? (int) $data['category_id'] : Category::assetPurchaseCategoryId();
        if (! Category::whereKey($categoryId)->exists()) {
            throw new \DomainException('Categoria inválida.');
        }

        if ($status === 'paid') {
            if (! $date) {
                throw new \DomainException('Informe a data do pagamento.');
            }
            if (! $method) {
                throw new \DomainException('Escolha se pagou pela conta ou no cartão.');
            }
            if ($method === 'account' && (! $accountId || ! BankAccount::whereKey($accountId)->exists())) {
                throw new \DomainException('Escolha a conta de onde saiu o dinheiro.');
            }
            if ($method === 'card' && (! $cardId || ! CreditCard::whereKey($cardId)->exists())) {
                throw new \DomainException('Escolha o cartão.');
            }
        }
        if ($accountId && ! BankAccount::whereKey($accountId)->exists()) {
            throw new \DomainException('Conta inválida.');
        }
        if ($cardId && ! CreditCard::whereKey($cardId)->exists()) {
            throw new \DomainException('Cartão inválido.');
        }

        return [
            'status' => $status,
            'date' => $date,
            'amount' => number_format($amount, 2, '.', ''),
            'method' => $method,
            'bank_account_id' => $method === 'account' ? $accountId : null,
            'credit_card_id' => $method === 'card' ? $cardId : null,
            'installments' => $method === 'card' ? $installments : 1,
            'category_id' => $categoryId,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
        ];
    }

    private function generate(PropertyPayment $payment): void
    {
        $property = $payment->property;
        $description = mb_substr('Bem: '.$property->description.($payment->notes ? ' · '.$payment->notes : ''), 0, 180);
        $link = ['source_type' => PropertyPayment::class, 'source_id' => $payment->id];

        if ($payment->method === 'account') {
            $this->transactions->create([
                'type' => 'expense',
                'status' => 'settled',
                'date' => $payment->date->toDateString(),
                'amount' => $payment->amount,
                'description' => $description,
                'bank_account_id' => $payment->bank_account_id,
                'category_id' => $payment->category_id,
            ] + $link);

            return;
        }

        $created = $this->installments->split(
            CreditCard::findOrFail($payment->credit_card_id),
            Carbon::parse($payment->date),
            (float) $payment->amount,
            $payment->installments,
            $description,
            $payment->category_id,
        );
        Transaction::whereIn('id', collect($created)->pluck('id'))->update($link);
    }

    private function invalidate(): void
    {
        app(LoanService::class)->invalidate();
    }
}

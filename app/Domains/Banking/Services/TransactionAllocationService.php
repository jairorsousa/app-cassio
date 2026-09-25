<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionAllocationService
{
    /** @param array<int, array{category_id: int|string|null, amount: string}> $rows */
    public function replace(Transaction $transaction, array $rows): void
    {
        DB::transaction(function () use ($transaction, $rows) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->isReadOnly() || ! in_array($transaction->type, ['income', 'expense'], true)) {
                throw new \DomainException('Este lançamento não pode ser rateado.');
            }

            if (count($rows) < 2 || count($rows) > 20) {
                throw new \InvalidArgumentException('Informe de 2 a 20 categorias para o rateio.');
            }

            $categoryIds = [];
            $amounts = [];

            foreach ($rows as $row) {
                $categoryId = $row['category_id'] ?? null;

                if (! ctype_digit((string) $categoryId) || (int) $categoryId < 1) {
                    throw new \InvalidArgumentException('Selecione uma categoria em cada linha do rateio.');
                }

                $categoryIds[] = (int) $categoryId;
                $amounts[] = $this->cents((string) ($row['amount'] ?? ''));
            }

            if (count(array_unique($categoryIds)) !== count($categoryIds)) {
                throw new \InvalidArgumentException('Cada categoria deve aparecer apenas uma vez no rateio.');
            }

            $validCategories = Category::active()
                ->where('type', $transaction->type)
                ->whereIn('id', $categoryIds)
                ->count();

            if ($validCategories !== count($categoryIds)) {
                throw new \InvalidArgumentException('Escolha categorias ativas do mesmo tipo do lançamento.');
            }

            if (array_sum($amounts) !== $this->cents((string) $transaction->amount)) {
                throw new \InvalidArgumentException('A soma do rateio deve ser igual ao valor total do lançamento.');
            }

            $transaction->allocations()->delete();

            foreach ($categoryIds as $index => $categoryId) {
                $transaction->allocations()->create([
                    'category_id' => $categoryId,
                    'amount' => intdiv($amounts[$index], 100).'.'.str_pad((string) ($amounts[$index] % 100), 2, '0', STR_PAD_LEFT),
                ]);
            }

            $transaction->category_id = null;
            $transaction->save();
            $transaction->touch();
        });
    }

    public function clear(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->isReadOnly()) {
                throw new \DomainException('Este lançamento não pode ser alterado.');
            }

            $transaction->allocations()->delete();
            $transaction->touch();
        });
    }

    private function cents(string $value): int
    {
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException('Informe valores válidos com até duas casas decimais.');
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $integer * 100) + (int) str_pad($fraction, 2, '0');

        if ($cents <= 0) {
            throw new \InvalidArgumentException('Cada parte do rateio deve ter valor maior que zero.');
        }

        return $cents;
    }
}

<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

class TransactionAllocationService
{
    /** Convert percentages with two decimal places to monetary amounts in cents. */
    public function fromPercentages(string $amount, array $rows, bool $requireComplete = true): array
    {
        $total = $this->cents($amount);
        $weights = [];
        foreach ($rows as $row) {
            $value = (string) ($row['percentage'] ?? '');
            if (! $requireComplete && $value === '') {
                $value = '0';
            }
            if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value)) {
                throw new \InvalidArgumentException('Informe percentuais válidos com até duas casas decimais.');
            }
            [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $weight = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
            if ($weight > 10000 || ($requireComplete && $weight <= 0)) {
                throw new \InvalidArgumentException('Cada percentual deve ser maior que zero e no máximo 100%.');
            }
            $weights[] = $weight;
        }
        if ($requireComplete && array_sum($weights) !== 10000) {
            throw new \InvalidArgumentException('A soma dos percentuais deve ser igual a 100%.');
        }

        $parts = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            [$part, $remainder] = BigInteger::of($total)->multipliedBy($weight)->quotientAndRemainder(10000);
            $parts[$index] = $part->toInt();
            $remainders[$index] = $remainder->toInt();
        }
        if (array_sum($weights) === 10000) {
            $leftover = $total - array_sum($parts);
            arsort($remainders, SORT_NUMERIC);
            foreach (array_keys($remainders) as $index) {
                if ($leftover-- <= 0) {
                    break;
                }
                $parts[$index]++;
            }
        }
        foreach ($rows as $index => &$row) {
            $row['amount'] = intdiv($parts[$index], 100).'.'.str_pad((string) ($parts[$index] % 100), 2, '0', STR_PAD_LEFT);
        }
        unset($row);
        return $rows;
    }

    public function toPercentages(string $amount, array $rows): array
    {
        $total = $this->cents($amount);
        $parts = [];
        $remainders = [];
        $sum = 0;
        foreach ($rows as $index => $row) {
            $value = (string) ($row['amount'] ?? '');
            $cents = $value === '' || (is_numeric($value) && (float) $value === 0.0) ? 0 : $this->cents($value);
            $sum += $cents;
            [$part, $remainder] = BigInteger::of($cents)->multipliedBy(10000)->quotientAndRemainder($total);
            $parts[$index] = $part->toInt();
            $remainders[$index] = $remainder->toInt();
        }
        if ($sum === $total) {
            $leftover = 10000 - array_sum($parts);
            arsort($remainders, SORT_NUMERIC);
            foreach (array_keys($remainders) as $index) {
                if ($leftover-- <= 0) {
                    break;
                }
                $parts[$index]++;
            }
        }
        foreach ($rows as $index => &$row) {
            $row['percentage'] = intdiv($parts[$index], 100).'.'.str_pad((string) ($parts[$index] % 100), 2, '0', STR_PAD_LEFT);
        }
        unset($row);
        return $rows;
    }

    /** @param array<int, array{category_id: int|string|null, amount: string}> $rows */
    public function replace(Transaction $transaction, array $rows): void
    {
        DB::transaction(function () use ($transaction, $rows) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->isReadOnly() || ! in_array($transaction->type, ['income', 'expense'], true)) {
                throw new \DomainException('Este lançamento não pode ser rateado.');
            }

            [$categoryIds, $amounts] = $this->validateRows($transaction->type, (string) $transaction->amount, $rows);

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

    /** Validate before saving so invalid allocations cannot leave a partial transaction. */
    public function validateRows(string $type, string $amount, array $rows): array
    {
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
            ->where('type', $type)
            ->whereIn('id', $categoryIds)
            ->count();

        if ($validCategories !== count($categoryIds)) {
            throw new \InvalidArgumentException('Escolha categorias ativas do mesmo tipo do lançamento.');
        }

        if (array_sum($amounts) !== $this->cents($amount)) {
            throw new \InvalidArgumentException('A soma do rateio deve ser igual ao valor total do lançamento.');
        }

        return [$categoryIds, $amounts];
    }

    /** Distribute a purchase's category totals across its monthly installments in cents. */
    public function replaceForTransactions(array $transactions, array $rows): void
    {
        if ($transactions === []) {
            throw new \InvalidArgumentException('Informe um lançamento para o rateio.');
        }
        foreach ($transactions as $transaction) {
            if ($transaction->isReadOnly() || ! in_array($transaction->type, ['income', 'expense'], true) || $transaction->type !== $transactions[0]->type) {
                throw new \DomainException('Este lançamento não pode ser rateado.');
            }
        }

        DB::transaction(function () use ($transactions, $rows) {
            $total = array_sum(array_map(fn ($transaction) => $this->cents((string) $transaction->amount), $transactions));
            [$categoryIds, $remaining] = $this->validateRows(
                $transactions[0]->type,
                intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT),
                $rows,
            );

            foreach ($transactions as $transaction) {
                $target = $this->cents((string) $transaction->amount);
                $parts = array_map(fn ($amount) => BigInteger::of($amount)->multipliedBy($target)->quotient($total)->toInt(), $remaining);
                $leftover = $target - array_sum($parts);
                foreach ($parts as $index => $part) {
                    if ($leftover > 0 && $part < $remaining[$index]) {
                        $parts[$index]++;
                        $leftover--;
                    }
                }

                $allocatedRows = [];
                foreach ($parts as $index => $part) {
                    $remaining[$index] -= $part;
                    if ($part > 0) {
                        $allocatedRows[] = [
                            'category_id' => $categoryIds[$index],
                            'amount' => intdiv($part, 100).'.'.str_pad((string) ($part % 100), 2, '0', STR_PAD_LEFT),
                        ];
                    }
                }
                if (count($allocatedRows) === 1) {
                    $this->clear($transaction);
                    $transaction->update(['category_id' => $allocatedRows[0]['category_id']]);
                } else {
                    $this->replace($transaction, $allocatedRows);
                }
                $total -= $target;
            }
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

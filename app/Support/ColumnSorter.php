<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Collection;

/** Ordenação de listas em memória: data real, número e texto em pt-BR. Só altera a exibição. */
class ColumnSorter
{
    /**
     * @param  array<string, callable(mixed): mixed>  $accessors
     * @param  array<int, callable(mixed): mixed|array{0: callable(mixed): mixed, 1: string}>  $tiebreakers
     */
    public static function sort(iterable $items, string $by, string $dir, array $accessors, array $tiebreakers = []): Collection
    {
        $items = Collection::make($items);
        if (! isset($accessors[$by])) {
            return $items->values();
        }

        $collator = class_exists(\Collator::class) ? \Collator::create('pt_BR') : null;
        $mul = $dir === 'desc' ? -1 : 1;

        return $items->sort(function ($a, $b) use ($accessors, $by, $mul, $collator, $tiebreakers) {
            $cmp = self::compare($accessors[$by]($a), $accessors[$by]($b), $collator) * $mul;
            if ($cmp !== 0) {
                return $cmp;
            }
            foreach ($tiebreakers as $tb) {
                if (is_array($tb)) {
                    [$fn, $tbDir] = $tb + [1 => 'asc'];
                } else {
                    $fn = $tb;
                    $tbDir = 'asc';
                }
                $cmp = self::compare($fn($a), $fn($b), $collator);
                if ($cmp !== 0) {
                    return $tbDir === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        })->values();
    }

    public static function compare(mixed $a, mixed $b, ?\Collator $collator = null): int
    {
        $aEmpty = self::isEmpty($a);
        $bEmpty = self::isEmpty($b);
        if ($aEmpty && $bEmpty) {
            return 0;
        }
        if ($aEmpty) {
            return -1;
        }
        if ($bEmpty) {
            return 1;
        }

        $aDate = self::toTimestamp($a);
        $bDate = self::toTimestamp($b);
        if ($aDate !== null && $bDate !== null) {
            return $aDate <=> $bDate;
        }

        if (self::isNumeric($a) && self::isNumeric($b)) {
            return (float) $a <=> (float) $b;
        }

        $sa = mb_strtolower(trim((string) $a), 'UTF-8');
        $sb = mb_strtolower(trim((string) $b), 'UTF-8');
        if ($collator) {
            $cmp = $collator->compare($sa, $sb);

            return $cmp === false ? 0 : $cmp;
        }

        return $sa <=> $sb;
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || (is_string($v) && trim($v) === '');
    }

    private static function isNumeric(mixed $v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric(str_replace(',', '.', $v)));
    }

    public static function paginate(\Illuminate\Support\Collection $items, object $component, int $perPage = 25): \Illuminate\Pagination\LengthAwarePaginator
    {
        $page = method_exists($component, 'getPage') ? (int) $component->getPage() : (int) request()->input('page', 1);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );
    }

    private static function toTimestamp(mixed $v): ?int
    {
        if ($v instanceof DateTimeInterface) {
            return $v->getTimestamp();
        }

        return null;
    }
}

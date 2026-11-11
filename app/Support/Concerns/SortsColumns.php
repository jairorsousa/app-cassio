<?php

namespace App\Support\Concerns;

use App\Support\UserPreferences;

/**
 * Ordenação de tabelas lembrada por usuário (lista fechada de colunas).
 * Páginas com uma tabela usam $sortBy/$sortDir e sortListKey().
 * Páginas com várias tabelas passam o $list em sortByColumn() e leem $sorts[$list].
 */
trait SortsColumns
{
    public string $sortBy = 'date';

    public string $sortDir = 'desc';

    /** @var array<string, array{by: string, dir: string}> */
    public array $sorts = [];

    protected function sortListKey(): string
    {
        return 'transactions';
    }

    /** @return list<string> */
    protected function sortLists(): array
    {
        return [$this->sortListKey()];
    }

    public function sortByColumn(string $column, ?string $list = null): void
    {
        $list = $this->normalizeList($list);
        [$by, $dir] = $this->sortState($list);
        $next = $column === $by ? ($dir === 'asc' ? 'desc' : 'asc') : $this->defaultDirFor($column, $list);
        $this->applySort($column, $next, $list);
    }

    public function toggleSortDir(?string $list = null): void
    {
        $list = $this->normalizeList($list);
        [$by, $dir] = $this->sortState($list);
        $this->applySort($by, $dir === 'asc' ? 'desc' : 'asc', $list);
    }

    public function updatedSortBy($value): void
    {
        $this->applySort((string) $value, $this->defaultDirFor((string) $value, $this->sortListKey()), $this->sortListKey());
    }

    public function updatedSortDir($value): void
    {
        $this->applySort($this->sortBy, (string) $value, $this->sortListKey());
    }

    /** Seletor "Ordenar por" de uma tabela que não é a principal da tela. */
    public function selectSort(string $list, string $by): void
    {
        $list = $this->normalizeList($list);
        $this->applySort($by, $this->defaultDirFor($by, $list), $list);
    }

    /** @return array{0: string, 1: string} */
    public function sortState(?string $list = null): array
    {
        $list = $this->normalizeList($list);
        if (isset($this->sorts[$list]['by'], $this->sorts[$list]['dir'])) {
            return UserPreferences::validSort((string) $this->sorts[$list]['by'], (string) $this->sorts[$list]['dir'], $list);
        }

        return UserPreferences::sort(auth()->user(), $list);
    }

    protected function defaultDirFor(string $column, ?string $list = null): string
    {
        $list = $this->normalizeList($list);
        [$defaultBy, $defaultDir] = UserPreferences::sortDefault($list);

        return $column === $defaultBy ? $defaultDir : 'asc';
    }

    protected function applySort(string $by, string $dir, ?string $list = null): void
    {
        $list = $this->normalizeList($list);
        [$by, $dir] = UserPreferences::setSort(auth()->user(), $by, $dir, $list);
        $this->sorts[$list] = ['by' => $by, 'dir' => $dir];
        if ($list === $this->sortListKey()) {
            $this->sortBy = $by;
            $this->sortDir = $dir;
        }
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    protected function loadSort(): void
    {
        foreach ($this->sortLists() as $list) {
            [$by, $dir] = UserPreferences::sort(auth()->user(), $list);
            $this->sorts[$list] = ['by' => $by, 'dir' => $dir];
        }
        $primary = $this->sortListKey();
        [$this->sortBy, $this->sortDir] = $this->sortState($primary);
    }

    /** Ordena uma coleção pela escolha lembrada da lista (só exibição). */
    protected function sortRows(iterable $items, string $list, array $accessors, array $tiebreakers = []): \Illuminate\Support\Collection
    {
        [$by, $dir] = $this->sortState($list);

        return \App\Support\ColumnSorter::sort($items, $by, $dir, $accessors, $tiebreakers);
    }

    private function normalizeList(?string $list): string
    {
        $list = $list ?: $this->sortListKey();

        return array_key_exists($list, UserPreferences::SORT_LISTS) ? $list : $this->sortListKey();
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Registros marcados como teste (is_test) ficam fora de listas, totais, saldos e relatórios.
 * Use ->withTests() para exibi-los (ex.: filtro "Mostrar testes").
 */
trait HidesTestRecords
{
    public static function bootHidesTestRecords(): void
    {
        static::addGlobalScope('not_test', function (Builder $query) {
            $query->where($query->getModel()->getTable().'.is_test', false);
        });
    }

    public function scopeWithTests(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_test');
    }

    public function scopeOnlyTests(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_test')->where($this->getTable().'.is_test', true);
    }
}

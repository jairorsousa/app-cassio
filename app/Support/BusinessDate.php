<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Data de negócio do escritório (America/Fortaleza). O app grava timestamps em UTC
 * (config app.timezone), então "hoje" em validações de data não pode usar o 'today' do Laravel:
 * entre 21h e 24h locais ele já seria o dia seguinte.
 */
class BusinessDate
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'America/Fortaleza');
    }

    public static function now(): Carbon
    {
        return Carbon::now(static::timezone());
    }

    public static function today(): string
    {
        return static::now()->toDateString();
    }
}

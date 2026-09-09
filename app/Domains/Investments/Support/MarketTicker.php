<?php

namespace App\Domains\Investments\Support;

class MarketTicker
{
    public static function isListed(string $ticker): bool
    {
        return (bool) preg_match('/^[A-Z]{4}\d{1,2}$/', strtoupper(trim($ticker)));
    }
}

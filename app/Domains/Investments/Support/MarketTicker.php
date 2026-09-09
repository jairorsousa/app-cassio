<?php

namespace App\Domains\Investments\Support;

class MarketTicker
{
    public static function isListed(string $ticker): bool
    {
        return (bool) preg_match('/^[A-Z]{4}\d{1,2}$/', strtoupper(trim($ticker)));
    }

    public static function classSlug(?string $type, ?string $subType): string
    {
        $sub = strtolower((string) $subType);
        $kind = strtolower((string) $type);

        return match (true) {
            in_array($sub, ['fii', 'fi-infra', 'fi-agro', 'fip', 'fidc'], true) => 'fiis',
            $sub === 'etf' => 'etfs',
            $sub === 'bdr' || $kind === 'bdr' => 'bdrs',
            in_array($sub, ['stock', 'unit'], true) || $kind === 'stock' => 'acoes',
            default => 'outros',
        };
    }

    public static function className(string $slug): string
    {
        return match ($slug) {
            'acoes' => 'Ações',
            'fiis' => 'FIIs',
            'etfs' => 'ETFs',
            'bdrs' => 'BDRs',
            default => 'Outros',
        };
    }
}

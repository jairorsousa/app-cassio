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

    /**
     * @return array<string, string>
     */
    public static function classes(): array
    {
        return [
            'acoes' => 'Ações',
            'fiis' => 'FIIs',
            'stocks' => 'Stocks',
            'bdrs' => 'BDRs',
            'etfs' => 'ETFs',
            'etfs-internacionais' => 'ETFs Internacionais',
            'reits' => 'REITs',
            'criptomoedas' => 'Criptomoedas',
            'renda-fixa' => 'Renda Fixa',
            'tesouro-direto' => 'Tesouro Direto',
            'fundos-de-investimentos' => 'Fundos de Investimentos',
            'outros' => 'Outros',
        ];
    }

    public static function className(string $slug): string
    {
        return self::classes()[$slug] ?? 'Outros';
    }
}

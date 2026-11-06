<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Support\MarketTicker;

class RefreshAssetQuotesService
{
    public function __construct(
        private BrapiQuoteProvider $provider,
        private AssetPositionService $positions,
    ) {}

    /**
     * @return array{updated: list<string>, failed: list<string>, skipped: list<string>}
     */
    public function refresh(): array
    {
        $updated = [];
        $failed = [];
        $skipped = [];

        // Só ativos em carteira: posição por operações ou a última posição B3 confirmada (não todo o histórico de proventos).
        $b3Ids = \App\Domains\Investments\Models\B3PositionSnapshot::latestConfirmed()->pluck('asset_id')->filter()->all();
        $assets = Asset::query()
            ->with('position')
            ->where(fn ($q) => $q->whereHas('position', fn ($query) => $query->where('quantity', '>', 0))->orWhereIn('id', $b3Ids))
            ->orderBy('ticker')
            ->get();

        foreach ($assets as $asset) {
            if (! MarketTicker::isListed((string) $asset->ticker)) {
                $skipped[] = $asset->ticker;

                continue;
            }

            $quote = $this->provider->quote($asset->ticker);
            if ($quote === null) {
                $failed[] = $asset->ticker;

                continue;
            }

            $this->positions->setQuote($asset, $quote['date'], $quote['price'], $quote['market_time'] ?? null, 'brapi');
            $updated[] = $asset->ticker;
        }

        return compact('updated', 'failed', 'skipped');
    }
}

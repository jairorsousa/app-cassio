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

        $assets = Asset::query()
            ->with('position')
            ->whereHas('position', fn ($query) => $query->where('quantity', '>', 0))
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

            $this->positions->setQuote($asset, $quote['date'], $quote['price']);
            $updated[] = $asset->ticker;
        }

        return compact('updated', 'failed', 'skipped');
    }
}

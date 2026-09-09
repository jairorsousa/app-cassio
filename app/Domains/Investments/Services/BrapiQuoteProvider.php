<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Support\MarketTicker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrapiQuoteProvider
{
    /**
     * @return array{ticker: string, price: float, date: string}|null
     */
    public function quote(string $ticker): ?array
    {
        $ticker = strtoupper(trim($ticker));

        try {
            $response = $this->client()->throw()->get($this->baseUrl().'/api/v2/stocks/quote', [
                'symbols' => $ticker,
            ]);
        } catch (RequestException $e) {
            Log::warning('Brapi quote request failed.', [
                'ticker' => $ticker,
                'status' => $e->response?->status(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Brapi quote request failed.', [
                'ticker' => $ticker,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $row = collect($response->json('results', []))->first();
        if (! is_array($row)) {
            return null;
        }

        $data = is_array($row['data'] ?? null) ? $row['data'] : $row;
        $price = round((float) ($data['regularMarketPrice'] ?? 0), 4);
        if ($price <= 0) {
            return null;
        }

        $marketTime = $data['regularMarketTime'] ?? $row['regularMarketTime'] ?? null;
        $today = now('America/Sao_Paulo')->toDateString();
        $date = is_string($marketTime) && $marketTime !== ''
            ? Carbon::parse($marketTime)->timezone('America/Sao_Paulo')->toDateString()
            : $today;

        if ($date > $today) {
            $date = $today;
        }

        return [
            'ticker' => (string) ($row['symbol'] ?? $data['symbol'] ?? $ticker),
            'price' => $price,
            'date' => $date,
        ];
    }

    /**
     * @return array{ticker: string, name: string, sector: ?string, class_slug: string}|null
     */
    public function lookup(string $ticker): ?array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            return null;
        }

        try {
            $response = $this->client()->get($this->baseUrl().'/api/quote/list', [
                'search' => $ticker,
                'limit' => 20,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Brapi asset lookup failed.', [
                'ticker' => $ticker,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Brapi asset lookup failed.', [
                'ticker' => $ticker,
                'status' => $response->status(),
            ]);

            return null;
        }

        $item = collect($response->json('stocks', []))->first(
            fn ($row) => is_array($row) && strtoupper((string) ($row['stock'] ?? '')) === $ticker
        );
        if (! is_array($item)) {
            return null;
        }

        $name = trim((string) ($item['name'] ?? ''));
        $sector = trim((string) ($item['subsector'] ?? '')) ?: trim((string) ($item['sector'] ?? '')) ?: null;

        return [
            'ticker' => $ticker,
            'name' => $name !== '' ? $name : $ticker,
            'sector' => $sector,
            'class_slug' => MarketTicker::classSlug(
                isset($item['type']) ? (string) $item['type'] : null,
                isset($item['subType']) ? (string) $item['subType'] : null,
            ),
        ];
    }

    private function client(): PendingRequest
    {
        $request = Http::timeout((int) config('services.brapi.timeout', 8))->acceptJson();
        $token = config('services.brapi.token');
        if (filled($token)) {
            $request = $request->withToken((string) $token);
        }

        return $request;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.brapi.base_url', 'https://brapi.dev'), '/');
    }
}

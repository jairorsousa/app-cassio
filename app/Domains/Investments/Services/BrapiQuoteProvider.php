<?php

namespace App\Domains\Investments\Services;

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
        $base = rtrim((string) config('services.brapi.base_url', 'https://brapi.dev'), '/');
        $request = Http::timeout((int) config('services.brapi.timeout', 8))
            ->acceptJson()
            ->throw();

        $token = config('services.brapi.token');
        if (filled($token)) {
            $request = $request->withToken((string) $token);
        }

        try {
            $response = $request->get("{$base}/api/v2/stocks/quote", [
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
}

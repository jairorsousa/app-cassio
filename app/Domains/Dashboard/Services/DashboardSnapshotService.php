<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Dashboard\Models\DashboardSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardSnapshotService
{
    public const CACHE_KEY = 'dashboard:snapshot';
    public const CACHE_TTL = 1800; // 30 min

    public function __construct(private DashboardSnapshotBuilder $builder)
    {
    }

    public function refresh(): array
    {
        $payload = $this->builder->build();

        DashboardSnapshot::create([
            'payload' => $payload,
            'generated_at' => now(),
        ]);

        Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL);

        return $payload;
    }

    public function current(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('own_balance', $cached['patrimony'] ?? [])) {
            return $cached;
        }

        $payload = $this->builder->build();
        Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL);

        return $payload;
    }

    public function invalidate(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $exception) {
            Log::warning('Failed to invalidate dashboard snapshot cache.', [
                'exception' => $exception,
            ]);
        }
    }
}

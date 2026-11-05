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

        Cache::put(self::regimeKey(), $payload, self::CACHE_TTL);

        return $payload;
    }

    private static function regimeKey(): string
    {
        $regime = \App\Domains\Banking\Services\OwnFinancialService::currentRegime();

        return $regime === 'cash' ? self::CACHE_KEY : self::CACHE_KEY.':'.$regime;
    }

    public function current(): array
    {
        // O resultado do mês depende do regime (Caixa/Competência) escolhido pelo usuário.
        $key = self::regimeKey();
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('own_balance', $cached['patrimony'] ?? [])) {
            return $cached;
        }

        $payload = $this->builder->build();
        Cache::put($key, $payload, self::CACHE_TTL);

        return $payload;
    }

    public function invalidate(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::CACHE_KEY.':accrual');
        } catch (Throwable $exception) {
            Log::warning('Failed to invalidate dashboard snapshot cache.', [
                'exception' => $exception,
            ]);
        }
    }
}

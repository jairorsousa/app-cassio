<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Dashboard\Models\DashboardSnapshot;
use Illuminate\Support\Facades\Cache;

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
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $latest = DashboardSnapshot::latest_snapshot();
            if ($latest) {
                return $latest->payload;
            }

            return $this->refresh();
        });
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}

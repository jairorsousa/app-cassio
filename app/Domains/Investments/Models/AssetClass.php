<?php

namespace App\Domains\Investments\Models;

use App\Domains\Investments\Support\MarketTicker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetClass extends Model
{
    protected $fillable = ['name', 'slug', 'status'];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public static function ensureCatalog(): void
    {
        $slugs = array_keys(MarketTicker::classes());
        $existing = static::query()->whereIn('slug', $slugs)->pluck('slug');

        foreach (MarketTicker::classes() as $slug => $name) {
            if (! $existing->contains($slug)) {
                static::create(['slug' => $slug, 'name' => $name, 'status' => true]);
            }
        }
    }

    public static function ordered(): Collection
    {
        $order = array_keys(MarketTicker::classes());

        return static::active()
            ->get()
            ->sortBy(function (self $class) use ($order) {
                $index = array_search($class->slug, $order, true);

                return $index === false ? 1000 + $class->id : $index;
            })
            ->values();
    }
}

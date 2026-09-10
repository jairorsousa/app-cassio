<?php

namespace App\Domains\Investments\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Support\MarketTicker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;

class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = ['ticker', 'name', 'asset_class_id', 'sector', 'notes', 'status', 'institution', 'maturity_date', 'liquidity', 'automatic_liquidity', 'linked_bank_account_id'];

    protected $casts = [
        'status' => 'boolean',
        'maturity_date' => 'date',
        'automatic_liquidity' => 'boolean',
    ];

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(AssetOperation::class)->orderBy('date')->orderBy('id');
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(AssetDividend::class)->orderByDesc('payment_date');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(AssetQuote::class)->orderByDesc('date');
    }

    public function position(): HasOne
    {
        return $this->hasOne(AssetPosition::class);
    }

    public function linkedBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'linked_bank_account_id');
    }

    public function usesAutomaticLiquidity(): bool
    {
        return (bool) $this->automatic_liquidity;
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function setTickerAttribute(string $value): void
    {
        $this->attributes['ticker'] = strtoupper(trim($value));
    }

    public function isMarketQuoted(): bool
    {
        return MarketTicker::isListed((string) $this->ticker);
    }

    public static function findByTicker(string $ticker): ?self
    {
        return static::withTrashed()
            ->where('ticker', strtoupper(trim($ticker)))
            ->first();
    }

    public static function resolveByTicker(string $ticker, array $attributes = []): self
    {
        $ticker = strtoupper(trim($ticker));
        $asset = static::findByTicker($ticker);

        if ($asset) {
            return static::reuse($asset, $attributes);
        }

        try {
            return static::create(['ticker' => $ticker, 'status' => true] + $attributes);
        } catch (UniqueConstraintViolationException $e) {
            $asset = static::findByTicker($ticker);
            if (! $asset) {
                throw $e;
            }

            return static::reuse($asset, $attributes);
        }
    }

    private static function reuse(self $asset, array $attributes): self
    {
        if ($asset->trashed()) {
            $asset->restore();
        }

        $fill = array_filter(
            $attributes,
            fn ($value) => $value !== null && $value !== ''
        );
        $fill['status'] = true;
        $asset->fill($fill);
        $asset->save();

        return $asset->refresh();
    }
}

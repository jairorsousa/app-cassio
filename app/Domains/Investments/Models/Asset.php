<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = ['ticker', 'name', 'asset_class_id', 'sector', 'notes', 'status'];

    protected $casts = [
        'status' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function setTickerAttribute(string $value): void
    {
        $this->attributes['ticker'] = strtoupper(trim($value));
    }
}

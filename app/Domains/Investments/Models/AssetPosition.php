<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPosition extends Model
{
    protected $fillable = [
        'asset_id', 'quantity', 'average_price', 'total_invested',
        'realized_pnl_total', 'current_price', 'recalculated_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'average_price' => 'decimal:4',
        'total_invested' => 'decimal:2',
        'realized_pnl_total' => 'decimal:2',
        'current_price' => 'decimal:4',
        'recalculated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function marketValue(): float
    {
        $price = (float) ($this->current_price ?? $this->average_price);
        return round((float) $this->quantity * $price, 2);
    }

    public function unrealizedPnL(): float
    {
        return round($this->marketValue() - (float) $this->total_invested, 2);
    }

    public function unrealizedPnLPercent(): float
    {
        $invested = (float) $this->total_invested;
        if ($invested <= 0) return 0;
        return round(($this->unrealizedPnL() / $invested) * 100, 3);
    }
}

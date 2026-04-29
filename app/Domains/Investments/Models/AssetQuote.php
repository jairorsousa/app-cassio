<?php

namespace App\Domains\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetQuote extends Model
{
    protected $fillable = ['asset_id', 'date', 'price'];

    protected $casts = [
        'date' => 'date',
        'price' => 'decimal:4',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}

<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionAllocation extends Model
{
    protected $fillable = ['category_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

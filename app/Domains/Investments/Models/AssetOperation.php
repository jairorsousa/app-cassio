<?php

namespace App\Domains\Investments\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetOperation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'asset_id', 'date', 'type', 'quantity', 'unit_price',
        'fees', 'total', 'realized_pnl', 'bank_account_id', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:4',
        'fees' => 'decimal:2',
        'total' => 'decimal:2',
        'realized_pnl' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }
}

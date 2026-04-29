<?php

namespace App\Domains\Investments\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetDividend extends Model
{
    use SoftDeletes;

    public const TYPE_LABELS = [
        'dividend' => 'Dividendo',
        'jcp' => 'JCP',
        'fii' => 'Rendimento FII',
    ];

    protected $fillable = [
        'asset_id', 'payment_date', 'type', 'unit_amount',
        'quantity', 'total', 'bank_account_id', 'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'unit_amount' => 'decimal:6',
        'quantity' => 'decimal:6',
        'total' => 'decimal:2',
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

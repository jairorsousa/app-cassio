<?php

namespace App\Domains\Banking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatementImportBatch extends Model
{
    protected $fillable = [
        'user_id', 'credit_card_id', 'bank_account_id', 'filename', 'source_label',
        'invoice_mode', 'invoice_month', 'items_count', 'total_amount', 'imported_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'imported_at' => 'datetime',
    ];

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'import_batch_id');
    }
}

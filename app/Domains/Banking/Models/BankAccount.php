<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'bank', 'agency', 'number', 'type',
        'initial_balance', 'status', 'notes',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function balance(): float
    {
        $signed = (float) $this->transactions()
            ->where('status', 'settled')
            ->selectRaw("SUM(CASE
                WHEN type = 'income' THEN amount
                WHEN type IN ('expense', 'invoice_payment') THEN -amount
                WHEN type = 'transfer' THEN amount
                ELSE 0
            END) as total")
            ->value('total') ?? 0;

        return (float) $this->initial_balance + $signed;
    }
}

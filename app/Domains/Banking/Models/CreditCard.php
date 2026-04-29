<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'brand', 'bank', 'limit',
        'closing_day', 'due_day',
        'default_payment_account_id', 'status', 'notes',
    ];

    protected $casts = [
        'limit' => 'decimal:2',
        'status' => 'boolean',
        'closing_day' => 'integer',
        'due_day' => 'integer',
    ];

    public function defaultPaymentAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_payment_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}

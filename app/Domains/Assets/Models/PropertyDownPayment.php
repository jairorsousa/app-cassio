<?php

namespace App\Domains\Assets\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada (ou outra antecipação) na compra de um bem, à parte do financiamento. */
class PropertyDownPayment extends Model
{
    protected $fillable = [
        'property_id', 'status', 'date', 'amount', 'bank_account_id', 'transaction_id',
        'linked_existing', 'linked_previous_category_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'linked_existing' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}

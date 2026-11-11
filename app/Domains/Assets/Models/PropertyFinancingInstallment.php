<?php

namespace App\Domains\Assets\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma parcela do financiamento de um bem. */
class PropertyFinancingInstallment extends Model
{
    protected $fillable = [
        'property_financing_id', 'number', 'due_date', 'amount', 'status', 'paid_at',
        'bank_account_id', 'transaction_id', 'linked_existing', 'linked_previous_category_id',
        'category_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'date',
        'amount' => 'decimal:2',
        'linked_existing' => 'boolean',
        'number' => 'integer',
    ];

    public function financing(): BelongsTo
    {
        return $this->belongsTo(PropertyFinancing::class, 'property_financing_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function property(): ?Property
    {
        return $this->financing?->property;
    }
}

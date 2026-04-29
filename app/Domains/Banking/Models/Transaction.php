<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Transaction extends Model
{
    use SoftDeletes;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'date', 'amount', 'description', 'status',
                'category_id', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('banking');
    }

    protected $fillable = [
        'type', 'date', 'amount', 'description', 'notes', 'status',
        'category_id', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id',
        'related_transaction_id', 'source_type', 'source_id',
        'installment_group_id', 'installment_number', 'installment_total',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'installment_number' => 'integer',
        'installment_total' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }

    public function isReadOnly(): bool
    {
        return ! is_null($this->source_type);
    }

    public function isInstallment(): bool
    {
        return ! is_null($this->installment_group_id);
    }
}

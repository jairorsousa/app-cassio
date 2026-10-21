<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptDestinationPayment extends Model
{
    protected $fillable = ['destination_id', 'transaction_id', 'transaction_created', 'settlement_transaction_id', 'request_key', 'writ_income_id', 'writ_amount'];

    protected $casts = ['transaction_created' => 'boolean', 'writ_amount' => 'decimal:2'];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ReceiptDestination::class, 'destination_id');
    }

    public function settlementTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'settlement_transaction_id');
    }

    public function writIncome(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'writ_income_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

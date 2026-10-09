<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptDestinationPayment extends Model
{
    protected $fillable = ['destination_id', 'transaction_id', 'transaction_created'];

    protected $casts = ['transaction_created' => 'boolean'];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ReceiptDestination::class, 'destination_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

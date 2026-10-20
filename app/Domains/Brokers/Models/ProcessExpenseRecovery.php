<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\ReceiptDestination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessExpenseRecovery extends Model
{
    protected $fillable = ['expense_id', 'destination_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(BrokerProcessExpense::class, 'expense_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ReceiptDestination::class, 'destination_id');
    }
}

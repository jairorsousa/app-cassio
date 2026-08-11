<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerAdvance extends Model
{
    protected $fillable = [
        'broker_id', 'date', 'amount', 'payment_method',
        'bank_account_id', 'transaction_id', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BrokerCommissionSettlement::class, 'advance_id');
    }

    /**
     * Total já compensado deste adiantamento.
     */
    public function settledAmount(): float
    {
        return (float) $this->settlements()->sum('amount_offset');
    }

    /**
     * Saldo restante deste adiantamento a compensar.
     */
    public function remainingBalance(): float
    {
        return round((float) $this->amount - $this->settledAmount(), 2);
    }
}

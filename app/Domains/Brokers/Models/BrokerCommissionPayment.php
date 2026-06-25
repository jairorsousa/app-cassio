<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerCommissionPayment extends Model
{
    protected $fillable = [
        'broker_id',
        'commission_id',
        'paid_at',
        'amount',
        'bank_account_id',
        'transaction_id',
        'notes',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'commission_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

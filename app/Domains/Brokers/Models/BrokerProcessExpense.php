<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerProcessExpense extends Model
{
    protected $fillable = ['broker_id', 'date', 'amount', 'description', 'bank_account_id', 'transaction_id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

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

    public function recoveries(): HasMany
    {
        return $this->hasMany(ProcessExpenseRecovery::class, 'expense_id');
    }

    public function recoveredCents(): int
    {
        return (int) round((float) $this->recoveries()->sum('amount') * 100);
    }

    public function remainingCents(): int
    {
        return max(0, (int) round((float) $this->amount * 100) - $this->recoveredCents());
    }
}

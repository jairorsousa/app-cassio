<?php

namespace App\Domains\Banking\Models;

use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\ProcessExpenseRecovery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class ReceiptDestination extends Model
{
    protected $fillable = ['receipt_id', 'kind', 'beneficiary', 'amount', 'commission_id', 'commission_created', 'repasse_account_id', 'allocation_transaction_id', 'contact_id'];

    protected $casts = ['amount' => 'decimal:2', 'commission_created' => 'boolean'];

    /** @return BelongsTo<Transaction, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'receipt_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'commission_id');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function repasseAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'repasse_account_id')->withTrashed();
    }

    public function allocationTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'allocation_transaction_id');
    }

    /** @return HasMany<ReceiptDestinationPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ReceiptDestinationPayment::class, 'destination_id');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(ProcessExpenseRecovery::class, 'destination_id');
    }

    /** @return HasMany<ReceivableSettlement, $this> */
    public function receivableOffsets(): HasMany
    {
        return $this->hasMany(ReceivableSettlement::class, 'destination_id');
    }

    public function offsetCents(): int
    {
        // Older data migrations also synchronize repasses before this table is created.
        if (! Schema::hasTable('receivable_settlements')) {
            return 0;
        }

        return (int) round((float) $this->receivableOffsets->sum('amount') * 100);
    }

    public function recoveredCents(): int
    {
        return (int) round((float) $this->recoveries->sum('amount') * 100);
    }

    public function paidCents(): int
    {
        if ($this->kind === 'own') {
            return 0;
        }
        if ($this->commission_id) {
            $commission = $this->commission;

            return $commission ? (int) round(($commission->paidAmount() + $commission->settledAmount()) * 100) : 0;
        }

        return $this->offsetCents() + $this->recoveredCents() + $this->payments->sum(fn ($payment) => $payment->writ_income_id ? (int) round((float) $payment->writ_amount * 100) : abs((int) round((float) $payment->transaction?->amount * 100)));
    }

    public function remainingCents(): int
    {
        return $this->kind === 'own' ? 0 : max(0, (int) round((float) $this->amount * 100) - $this->paidCents());
    }
}

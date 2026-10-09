<?php

namespace App\Domains\Banking\Models;

use App\Domains\Brokers\Models\BrokerCommission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptDestination extends Model
{
    protected $fillable = ['receipt_id', 'kind', 'beneficiary', 'amount', 'commission_id', 'commission_created'];

    protected $casts = ['amount' => 'decimal:2', 'commission_created' => 'boolean'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'receipt_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'commission_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceiptDestinationPayment::class, 'destination_id');
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
        return $this->payments->sum(fn ($payment) => (int) round((float) $payment->transaction?->amount * 100));
    }

    public function remainingCents(): int
    {
        return $this->kind === 'own' ? 0 : max(0, (int) round((float) $this->amount * 100) - $this->paidCents());
    }
}

<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrokerCommission extends Model
{
    protected $fillable = [
        'broker_id', 'case_type_id', 'name', 'base_amount',
        'percentage_applied', 'commission_amount',
        'status', 'reference_date', 'bank_account_id', 'notes', 'manual_advances',
    ];

    protected $casts = [
        'manual_advances' => 'boolean',
        'base_amount' => 'decimal:2',
        'percentage_applied' => 'decimal:3',
        'commission_amount' => 'decimal:2',
        'reference_date' => 'date',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function caseType(): BelongsTo
    {
        return $this->belongsTo(CaseType::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BrokerCommissionSettlement::class, 'commission_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BrokerCommissionPayment::class, 'commission_id');
    }

    public function receiptDestination(): HasOne
    {
        return $this->hasOne(ReceiptDestination::class, 'commission_id');
    }

    /**
     * Total já compensado por adiantamentos.
     */
    public function settledAmount(): float
    {
        return (float) $this->settlements()->sum('amount_offset');
    }

    /**
     * Total já repassado em dinheiro ao corretor.
     */
    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Saldo remanescente da comissão a pagar.
     */
    public function remainingAmount(): float
    {
        return round(max((float) $this->commission_amount - $this->settledAmount() - $this->paidAmount(), 0), 2);
    }
}

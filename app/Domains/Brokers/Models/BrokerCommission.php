<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrokerCommission extends Model
{
    use \App\Support\NormalizesProcessNumbers;

    public const PROCESS_NUMBER_FIELDS = ['process_ref'];

    protected $fillable = [
        'broker_id', 'case_type_id', 'name', 'process_ref', 'writ_id',
        'base_amount', 'percentage_applied', 'commission_mode', 'rate_or_amount', 'base',
        'commission_amount', 'status', 'reference_date', 'bank_account_id', 'notes', 'manual_advances',
    ];

    protected $casts = [
        'manual_advances' => 'boolean',
        'base_amount' => 'decimal:2',
        'percentage_applied' => 'decimal:3',
        'rate_or_amount' => 'decimal:3',
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

    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class);
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

    public function settledAmount(): float
    {
        return (float) $this->settlements()->sum('amount_offset');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return round(max((float) $this->commission_amount - $this->settledAmount() - $this->paidAmount(), 0), 2);
    }

    public function modeLabel(): string
    {
        return ($this->commission_mode === 'percent') ? 'Percentual' : 'Valor fixo';
    }

    public function calculationLabel(): string
    {
        if ($this->commission_mode === 'percent') {
            $rate = number_format((float) ($this->rate_or_amount ?: $this->percentage_applied), 3, ',', '.');

            return $rate.'% sobre R$ '.number_format((float) $this->base_amount, 2, ',', '.');
        }

        return 'Fixo R$ '.number_format((float) $this->commission_amount, 2, ',', '.');
    }
}

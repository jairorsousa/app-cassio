<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerAdvance extends Model
{
    use \App\Support\NormalizesProcessNumbers;

    public const PROCESS_NUMBER_FIELDS = ['process_ref'];

    public const KIND_PERSONAL = 'personal';

    public const KIND_PROCESS_EXPENSE = 'process_expense';

    protected $fillable = [
        'broker_id', 'kind', 'date', 'amount', 'payment_method',
        'bank_account_id', 'transaction_id', 'notes',
        'process_ref', 'writ_id', 'contact_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (BrokerAdvance $advance) {
            if (! in_array($advance->kind, [self::KIND_PERSONAL, self::KIND_PROCESS_EXPENSE], true)) {
                $advance->kind = self::KIND_PERSONAL;
            }
        });
    }

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

    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BrokerCommissionSettlement::class, 'advance_id');
    }

    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PERSONAL);
    }

    public function isPersonal(): bool
    {
        return ($this->kind ?: self::KIND_PERSONAL) === self::KIND_PERSONAL;
    }

    public function settledAmount(): float
    {
        return (float) $this->settlements()->sum('amount_offset');
    }

    public function remainingBalance(): float
    {
        if (! $this->isPersonal()) {
            return 0.0;
        }

        return round((float) $this->amount - $this->settledAmount(), 2);
    }
}

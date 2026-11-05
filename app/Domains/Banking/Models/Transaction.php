<?php

namespace App\Domains\Banking\Models;

use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\HidesTestRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Transaction extends Model
{
    use LogsActivity;
    use HidesTestRecords;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'date', 'amount', 'description', 'status',
                'category_id', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('banking');
    }

    protected $fillable = [
        'type', 'date', 'amount', 'description', 'notes', 'status',
        'category_id', 'bank_account_id', 'credit_card_id', 'credit_card_invoice_id',
        'related_transaction_id', 'source_type', 'source_id',
        'ofx_fitid',
        'installment_group_id', 'installment_number', 'installment_total',
        'is_test',
        'link_process', 'link_writ_id', 'link_broker_id', 'link_contact_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'installment_number' => 'integer',
        'installment_total' => 'integer',
        'is_test' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Transferência entre contas próprias recebe sempre a categoria de sistema.
        static::saving(function (Transaction $transaction) {
            if ($transaction->type === 'transfer') {
                $transaction->category_id = Category::transferCategoryId();
            }
        });
    }

    /** Exclui lançamentos anteriores ao saldo inicial datado da conta (históricos). */
    public function scopeNotHistorical(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(fn ($q) => $q->whereNull($table.'.bank_account_id')
            ->orWhereHas('bankAccount', fn ($a) => $a->withTrashed()->where(fn ($a) => $a->whereNull('initial_balance_date')
                ->orWhereColumn('bank_accounts.initial_balance_date', '<=', $table.'.date'))));
    }

    public function isHistorical(): bool
    {
        return (bool) $this->bankAccount?->isHistoricalDate($this->date);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ReceivableInstallment, $this> */
    public function receivableInstallments(): HasMany
    {
        return $this->hasMany(ReceivableInstallment::class);
    }

    /** @return HasMany<ReceivableSettlement, $this> */
    public function receivableSettlements(): HasMany
    {
        return $this->hasMany(ReceivableSettlement::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    public function processReceipt(): HasOne
    {
        return $this->hasOne(ProcessReceipt::class, 'receipt_id');
    }

    /** @return HasMany<ReceiptDestination, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(ReceiptDestination::class, 'receipt_id');
    }

    /** @return HasOne<ReceiptDestinationPayment, $this> */
    public function destinationPayment(): HasOne
    {
        return $this->hasOne(ReceiptDestinationPayment::class, 'transaction_id');
    }

    public function distributionPart(): HasOne
    {
        return $this->hasOne(ReceiptDestination::class, 'allocation_transaction_id');
    }

    public function writLinks(): HasMany
    {
        return $this->hasMany(ReceiptDestinationPayment::class, 'writ_income_id');
    }

    public function linkWrit(): BelongsTo
    {
        return $this->belongsTo(Writ::class, 'link_writ_id')->withTrashed();
    }

    public function linkBroker(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Brokers\Models\Broker::class, 'link_broker_id')->withTrashed();
    }

    public function linkContact(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Contacts\Models\Contact::class, 'link_contact_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class)->orderBy('id');
    }

    public function hasLinks(): bool
    {
        return $this->link_process || $this->link_writ_id || $this->link_broker_id || $this->link_contact_id;
    }

    public function sourceWrit(): BelongsTo
    {
        return $this->belongsTo(Writ::class, 'source_id');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** @return BelongsTo<CreditCardInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditCardInvoice::class, 'credit_card_invoice_id');
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }

    public function isReadOnly(): bool
    {
        return ! is_null($this->source_type);
    }

    public function isInstallment(): bool
    {
        return ! is_null($this->installment_group_id);
    }
}

<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ReceivableSettlement extends Model
{
    use LogsActivity;

    protected $fillable = ['receivable_installment_id', 'batch_key', 'kind', 'amount', 'date', 'transaction_id', 'transaction_created', 'destination_id', 'notes'];

    protected $casts = ['amount' => 'decimal:2', 'date' => 'date', 'transaction_created' => 'boolean'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('receivables');
    }

    /** @return BelongsTo<ReceivableInstallment, $this> */
    public function receivableInstallment(): BelongsTo
    {
        return $this->belongsTo(ReceivableInstallment::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<ReceiptDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(ReceiptDestination::class, 'destination_id');
    }
}

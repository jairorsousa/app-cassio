<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ReceivableInstallment extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('receivables');
    }

    protected $fillable = ['receivable_id', 'transaction_id', 'number', 'amount', 'due_date'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date'];

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return HasMany<ReceivableSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(ReceivableSettlement::class);
    }

    public function amountCents(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    public function remainingCents(): int
    {
        return max(0, $this->amountCents() - $this->settlements->sum(fn ($row) => (int) round((float) $row->amount * 100)));
    }
}

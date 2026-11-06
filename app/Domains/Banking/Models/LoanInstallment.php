<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class LoanInstallment extends Model
{
    use LogsActivity;

    protected $fillable = ['loan_id', 'number', 'amount', 'due_date'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('loans');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(LoanSettlement::class);
    }

    public function amountCents(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    public function remainingPrincipalCents(): int
    {
        $paid = $this->settlements->sum(fn ($row) => (int) round((float) $row->principal_amount * 100));

        return max(0, $this->amountCents() - $paid);
    }
}

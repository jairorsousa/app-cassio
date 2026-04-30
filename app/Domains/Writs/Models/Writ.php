<?php

namespace App\Domains\Writs\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Writ extends Model
{
    use SoftDeletes;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'stage', 'process_number', 'face_value', 'paid_amount',
                'estimated_receipt_amount', 'actual_receipt_amount', 'paid_at', 'finalized_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('writs');
    }

    public const STAGES = ['negotiation', 'pending', 'paid', 'petitioning', 'finalized'];

    public const STAGE_LABELS = [
        'negotiation' => 'Negociação',
        'pending' => 'Cessão Pendente',
        'paid' => 'Pago',
        'petitioning' => 'Peticionar',
        'finalized' => 'Finalizar',
    ];

    protected $fillable = [
        'type', 'stage',
        'process_number', 'court', 'debtor_entity', 'credit_nature',
        'assignor_name', 'assignor_document', 'assignor_contact',
        'assignor_bank_data', 'assignor_lawyer',
        'face_value', 'paid_amount', 'discount_percentage',
        'estimated_receipt_amount', 'estimated_months',
        'actual_receipt_amount', 'paid_at', 'finalized_at',
        'source_bank_account_id', 'destination_bank_account_id',
        'notes',
    ];

    protected $casts = [
        'face_value' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:3',
        'estimated_receipt_amount' => 'decimal:2',
        'actual_receipt_amount' => 'decimal:2',
        'estimated_months' => 'integer',
        'paid_at' => 'date',
        'finalized_at' => 'date',
    ];

    public function history(): HasMany
    {
        return $this->hasMany(WritStageHistory::class)->orderBy('transitioned_at');
    }

    public function assignors(): HasMany
    {
        return $this->hasMany(WritAssignor::class)->with('contact');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_bank_account_id');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? $this->stage;
    }

    public function isFinalized(): bool
    {
        return $this->stage === 'finalized';
    }

    public function discountPercentageCalculated(): float
    {
        if ((float) $this->face_value <= 0) {
            return 0.0;
        }

        return round((1 - ((float) $this->paid_amount / (float) $this->face_value)) * 100, 3);
    }

    public function estimatedProfit(): float
    {
        return round((float) $this->estimated_receipt_amount - (float) $this->paid_amount, 2);
    }

    public function actualProfit(): ?float
    {
        if ($this->actual_receipt_amount === null) {
            return null;
        }

        return round((float) $this->actual_receipt_amount - (float) $this->paid_amount, 2);
    }
}

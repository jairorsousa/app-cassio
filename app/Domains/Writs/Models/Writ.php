<?php

namespace App\Domains\Writs\Models;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Writ extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'stage', 'process_number', 'face_value', 'negotiated_amount', 'proposed_amount', 'paid_amount',
                'notary_expenses_amount', 'other_expenses_amount',
                'estimated_receipt_amount', 'actual_receipt_amount', 'cession_at', 'paid_at', 'finalized_at',
                'google_calendar_event_id', 'google_calendar_synced_at',
                'google_calendar_petition_event_id', 'google_calendar_petition_synced_at',
                'awaiting_receipt_at', 'google_calendar_awaiting_receipt_event_id', 'google_calendar_awaiting_receipt_synced_at',
                'lost_reason', 'lost_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('writs');
    }

    public const STAGES = ['negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost'];

    public const STAGE_LABELS = [
        'negotiation' => 'Negociação',
        'pending' => 'Cessão Pendente',
        'paid' => 'Pago',
        'petitioning' => 'Peticionar',
        'awaiting_receipt' => 'Aguardando Recebimento',
        'finalized' => 'Finalizar',
        'lost' => 'Perdido',
    ];

    protected $fillable = [
        'type', 'stage',
        'process_number', 'court', 'debtor_entity', 'credit_nature',
        'assignor_name', 'assignor_document', 'assignor_contact',
        'assignor_bank_data', 'assignor_lawyer',
        'face_value', 'negotiated_amount', 'proposed_amount', 'paid_amount', 'notary_expenses_amount', 'other_expenses_amount', 'discount_percentage',
        'estimated_receipt_amount', 'estimated_months',
        'actual_receipt_amount', 'cession_at', 'paid_at', 'petitioned_at', 'awaiting_receipt_at', 'finalized_at',
        'google_calendar_event_id', 'google_calendar_event_link', 'google_calendar_synced_at', 'google_calendar_sync_error',
        'google_calendar_petition_event_id', 'google_calendar_petition_event_link', 'google_calendar_petition_synced_at', 'google_calendar_petition_sync_error',
        'google_calendar_awaiting_receipt_event_id', 'google_calendar_awaiting_receipt_event_link', 'google_calendar_awaiting_receipt_synced_at', 'google_calendar_awaiting_receipt_sync_error',
        'lost_reason', 'lost_at',
        'source_bank_account_id', 'destination_bank_account_id',
        'notes',
    ];

    protected $casts = [
        'face_value' => 'decimal:2',
        'negotiated_amount' => 'decimal:2',
        'proposed_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'notary_expenses_amount' => 'decimal:2',
        'other_expenses_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:3',
        'estimated_receipt_amount' => 'decimal:2',
        'actual_receipt_amount' => 'decimal:2',
        'estimated_months' => 'integer',
        'cession_at' => 'datetime',
        'google_calendar_synced_at' => 'datetime',
        'paid_at' => 'date',
        'petitioned_at' => 'datetime',
        'google_calendar_petition_synced_at' => 'datetime',
        'awaiting_receipt_at' => 'datetime',
        'google_calendar_awaiting_receipt_synced_at' => 'datetime',
        'finalized_at' => 'date',
        'lost_at' => 'datetime',
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
        $baseValue = $this->negotiationBaseValue();

        if ($baseValue <= 0) {
            return 0.0;
        }

        $amount = $this->totalCost();

        return round((1 - ((float) $amount / $baseValue)) * 100, 3);
    }

    public function negotiationBaseValue(): float
    {
        $negotiated = (float) ($this->negotiated_amount ?? 0);

        return $negotiated > 0 ? $negotiated : (float) $this->face_value;
    }

    public function estimatedProfit(): float
    {
        return round((float) $this->estimated_receipt_amount - $this->totalCost(), 2);
    }

    public function actualProfit(): ?float
    {
        if ($this->actual_receipt_amount === null) {
            return null;
        }

        return round((float) $this->actual_receipt_amount - $this->totalCost(), 2);
    }

    public function totalExpenses(): float
    {
        return round((float) $this->notary_expenses_amount + (float) $this->other_expenses_amount, 2);
    }

    public function totalCost(): float
    {
        $amount = $this->paid_amount > 0 ? $this->paid_amount : $this->proposed_amount;

        return round((float) $amount + $this->totalExpenses(), 2);
    }

    public function estimatedProfitPercentage(): float
    {
        $cost = $this->totalCost();
        if ($cost <= 0) {
            return 0.0;
        }

        return round(($this->estimatedProfit() / $cost) * 100, 2);
    }

    public function estimatedProfitPerMonth(): float
    {
        if (! $this->estimated_months || $this->estimated_months <= 0) {
            return 0.0;
        }

        return round($this->estimatedProfit() / $this->estimated_months, 2);
    }

    public function estimatedProfitPercentagePerMonth(): float
    {
        if (! $this->estimated_months || $this->estimated_months <= 0) {
            return 0.0;
        }

        return round($this->estimatedProfitPercentage() / $this->estimated_months, 2);
    }

    public function actualMonths(): ?int
    {
        if (! $this->paid_at || ! $this->finalized_at) {
            return null;
        }

        $start = Carbon::parse($this->paid_at);
        $end = Carbon::parse($this->finalized_at);

        $months = $start->diffInMonths($end);

        return max(1, $months);
    }

    public function actualProfitPercentage(): ?float
    {
        if ($this->actualProfit() === null) {
            return null;
        }

        $cost = $this->totalCost();
        if ($cost <= 0) {
            return 0.0;
        }

        return round(($this->actualProfit() / $cost) * 100, 2);
    }

    public function actualProfitPercentagePerMonth(): ?float
    {
        if ($this->actualProfitPercentage() === null) {
            return null;
        }

        $months = $this->actualMonths();
        if (! $months || $months <= 0) {
            return 0.0;
        }

        return round($this->actualProfitPercentage() / $months, 2);
    }

    public static function calculateDiscountPercentage(float $base, float $amount): float
    {
        if ($base <= 0) {
            return 0;
        }

        $percentage = round((1 - $amount / $base) * 100, 3);

        return max(-999.999, min(999.999, $percentage));
    }
}

<?php

namespace App\Domains\Brokers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerCommissionRule extends Model
{
    protected $fillable = [
        'broker_id', 'case_type_id', 'percentage',
        'valid_from', 'valid_to',
    ];

    protected $casts = [
        'percentage' => 'decimal:3',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function caseType(): BelongsTo
    {
        return $this->belongsTo(CaseType::class);
    }

    /**
     * Scope para regra vigente em uma data.
     */
    public function scopeValidOn($query, string $date)
    {
        return $query
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            });
    }
}

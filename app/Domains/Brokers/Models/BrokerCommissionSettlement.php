<?php

namespace App\Domains\Brokers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerCommissionSettlement extends Model
{
    protected $fillable = [
        'commission_id', 'advance_id', 'amount_offset', 'settled_at',
    ];

    protected $casts = [
        'amount_offset' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'commission_id');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(BrokerAdvance::class, 'advance_id');
    }
}

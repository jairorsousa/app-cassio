<?php

namespace App\Domains\Brokers\Models;

use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WritDefaultBroker extends Model
{
    protected $fillable = [
        'writ_id', 'broker_id', 'base', 'mode', 'rate_or_amount',
    ];

    protected $casts = [
        'rate_or_amount' => 'decimal:3',
    ];

    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }
}

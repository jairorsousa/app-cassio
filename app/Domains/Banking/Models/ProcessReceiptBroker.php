<?php

namespace App\Domains\Banking\Models;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Participação de um corretor num recebimento de processo (base, modo, taxa/valor e valor calculado). */
class ProcessReceiptBroker extends Model
{
    protected $fillable = ['process_receipt_id', 'broker_id', 'base', 'mode', 'rate_or_amount', 'calculated_amount', 'compensated_amount', 'commission_id'];

    protected $casts = ['rate_or_amount' => 'decimal:2', 'calculated_amount' => 'decimal:2', 'compensated_amount' => 'decimal:2'];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class)->withTrashed();
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(BrokerCommission::class, 'commission_id');
    }
}

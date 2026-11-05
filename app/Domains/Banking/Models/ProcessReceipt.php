<?php

namespace App\Domains\Banking\Models;

use App\Domains\Brokers\Models\Broker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessReceipt extends Model
{
    protected $fillable = ['receipt_id', 'broker_id', 'writ_id', 'writ_amount', 'process_ref', 'fees_amount', 'category_id'];

    protected $casts = ['fees_amount' => 'decimal:2', 'writ_amount' => 'decimal:2'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'receipt_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class)->withTrashed();
    }

    public function writ(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Writs\Models\Writ::class)->withTrashed();
    }

    public function brokerShares(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProcessReceiptBroker::class)->orderBy('id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

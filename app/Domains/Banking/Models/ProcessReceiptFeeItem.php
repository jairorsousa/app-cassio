<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessReceiptFeeItem extends Model
{
    protected $fillable = ['process_receipt_id', 'kind', 'description', 'amount', 'in_commission_base'];

    protected $casts = ['amount' => 'decimal:2', 'in_commission_base' => 'boolean'];

    public function processReceipt(): BelongsTo
    {
        return $this->belongsTo(ProcessReceipt::class);
    }

    public function kindLabel(): string
    {
        return $this->kind === 'other' ? 'Outro' : 'Extra';
    }
}

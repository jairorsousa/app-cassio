<?php

namespace App\Domains\Divisions\Models;

use App\Domains\Banking\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DivisionSettlement extends Model
{
    use SoftDeletes;

    protected $fillable = ['group_id', 'from_member_id', 'to_member_id', 'amount', 'date', 'notes', 'transaction_id', 'created_by'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(DivisionMember::class, 'from_member_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(DivisionMember::class, 'to_member_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

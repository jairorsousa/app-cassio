<?php

namespace App\Domains\Divisions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DivisionExpenseShare extends Model
{
    protected $fillable = ['expense_id', 'member_id', 'input', 'amount'];

    protected $casts = ['amount' => 'decimal:2', 'input' => 'decimal:4'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(DivisionMember::class, 'member_id');
    }
}

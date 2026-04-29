<?php

namespace App\Domains\Writs\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WritStageHistory extends Model
{
    protected $table = 'writ_stage_history';

    protected $fillable = ['writ_id', 'from_stage', 'to_stage', 'transitioned_at', 'notes', 'user_id'];

    protected $casts = [
        'transitioned_at' => 'datetime',
    ];

    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

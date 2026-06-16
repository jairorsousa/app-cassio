<?php

namespace App\Domains\Integrations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarToken extends Model
{
    protected $fillable = [
        'provider',
        'calendar_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'connected_by_user_id',
        'connected_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'scopes' => 'array',
        'connected_at' => 'datetime',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public static function central(): ?self
    {
        return self::query()
            ->where('provider', 'google_calendar')
            ->latest('id')
            ->first();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailSendCredential extends Model
{
    public const PROVIDER = 'gmail_send';

    protected $fillable = [
        'provider',
        'account_email',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'connected_by_user_id',
        'connected_at',
        'last_self_test_status',
        'last_self_test_at',
        'last_self_test_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_self_test_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public static function current(): ?self
    {
        return self::query()->where('provider', self::PROVIDER)->orderBy('id')->first();
    }

    public function accessExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->lessThanOrEqualTo(now()->addMinute());
    }
}

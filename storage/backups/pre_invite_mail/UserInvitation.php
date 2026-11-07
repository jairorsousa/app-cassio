<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    protected $fillable = ['name', 'email', 'modules', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at', 'invited_by', 'accepted_user_id'];

    protected $casts = ['modules' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = ['token_hash'];

    public const VALID_DAYS = 7;

    public const STATUS_LABELS = ['pending' => 'Pendente', 'accepted' => 'Aceito', 'revoked' => 'Revogado', 'expired' => 'Expirado'];

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public static function findUsable(string $token): ?self
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $inv = self::where('token_hash', self::hashToken($token))->first();

        return $inv && $inv->status() === 'pending' ? $inv : null;
    }
}

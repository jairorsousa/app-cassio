<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'modules',
        'token_hash',
        'token_encrypted',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'invited_by',
        'accepted_user_id',
        'email_delivery_status',
        'email_dispatch_token',
        'email_attempts',
        'email_queued_at',
        'email_sent_at',
        'email_failed_at',
        'email_last_error',
    ];

    protected $casts = [
        'modules' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'email_queued_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'email_failed_at' => 'datetime',
    ];

    protected $hidden = ['token_hash', 'token_encrypted', 'email_dispatch_token'];

    public const VALID_DAYS = 7;

    public const STATUS_LABELS = ['pending' => 'Pendente', 'accepted' => 'Aceito', 'revoked' => 'Revogado', 'expired' => 'Expirado'];

    public const DELIVERY_LABELS = [
        'pending' => 'E-mail não enviado',
        'queued' => 'E-mail na fila',
        'sent' => 'E-mail enviado',
        'failed' => 'Falha no e-mail',
    ];

    /** Visíveis nas listas: aceitos, ou pendentes ainda válidos. Revogado/expirado sem conta não aparece. */
    public function scopeVisible($q)
    {
        return $q->where(fn ($q) => $q->whereNotNull('accepted_at')->orWhere(fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '>', now())));
    }

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

    /** Link atual, só se o convite está válido (pendente) e o token criptografado confere com o hash. */
    public function currentLink(): ?string
    {
        if ($this->status() !== 'pending' || ! $this->token_encrypted) {
            return null;
        }
        try {
            $token = \Illuminate\Support\Facades\Crypt::decryptString($this->token_encrypted);
        } catch (\Throwable) {
            return null;
        }

        return hash_equals((string) $this->token_hash, self::hashToken($token)) ? route('invite.accept', ['token' => $token]) : null;
    }

    public function deliveryLabel(): string
    {
        $s = $this->email_delivery_status;

        return self::DELIVERY_LABELS[$s] ?? 'E-mail não enviado';
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credencial SMTP do remetente de convites (singleton lógico).
 * A senha usa cast encrypted da APP_KEY; nunca expor valor nem comprimento.
 */
class MailSenderSetting extends Model
{
    public const ALLOWED_USERNAME = 'cassio@cassiomota.com';

    public const DEFAULT_HOST = 'smtp.gmail.com';

    public const DEFAULT_PORT = 587;

    public const DEFAULT_ENCRYPTION = 'tls';

    protected $fillable = [
        'username',
        'host',
        'port',
        'encryption',
        'password',
        'password_set_at',
        'last_self_test_status',
        'last_self_test_at',
        'last_self_test_error',
        'updated_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'password_set_at' => 'datetime',
            'last_self_test_at' => 'datetime',
            'port' => 'integer',
        ];
    }

    public function hasPassword(): bool
    {
        return $this->password_set_at !== null && $this->getRawOriginal('password') !== null && $this->getRawOriginal('password') !== '';
    }

    /** Configuração completa para envio (SMTP + senha). Não implica teste ok nem formato Google. */
    public function isReadyToSend(): bool
    {
        return $this->hasPassword()
            && $this->username === self::ALLOWED_USERNAME
            && $this->host === self::DEFAULT_HOST
            && (int) $this->port === self::DEFAULT_PORT
            && $this->encryption === self::DEFAULT_ENCRYPTION;
    }

    /**
     * Senha de aplicativo Google: 16 letras a-z (espaços entre grupos são opcionais e ignorados).
     * Só meta booleana — nunca expõe valor/comprimento na UI.
     */
    public function passwordFormatOk(): bool
    {
        if (! $this->hasPassword()) {
            return false;
        }
        $plain = $this->password;
        if (! is_string($plain)) {
            return false;
        }
        $normalized = self::normalizeAppPassword($plain);

        return (bool) preg_match('/^[a-z]{16}$/', $normalized);
    }

    /** Remove apenas espaços ASCII (formato "xxxx xxxx xxxx xxxx"). */
    public static function normalizeAppPassword(string $password): string
    {
        return str_replace(' ', '', $password);
    }

    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    public static function defaults(): array
    {
        return [
            'username' => self::ALLOWED_USERNAME,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'encryption' => self::DEFAULT_ENCRYPTION,
        ];
    }
}

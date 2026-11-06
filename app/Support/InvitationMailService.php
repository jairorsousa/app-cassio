<?php

namespace App\Support;

use App\Jobs\SendUserInvitationEmail;
use App\Mail\UserInvitationMail;
use App\Mail\MailSenderSelfTestMail;
use App\Models\MailSenderSetting;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuração SMTP do remetente de convites e disparo explícito de e-mail.
 * Override de transport só no momento do envio — não altera MAIL_MAILER global nem bootstrap.
 */
class InvitationMailService
{
    public const MAILER_NAME = 'invitation_smtp';

    public const TRANSPORT_TIMEOUT = 15;

    /** Somente o administrador id 1 configura o remetente SMTP. */
    public static function mailAdmin(?User $actor): User
    {
        abort_unless($actor && $actor->isActive() && $actor->isAdmin() && (int) $actor->id === 1, 403);

        return $actor;
    }

    public static function anyAdmin(?User $actor): User
    {
        abort_unless($actor && $actor->isActive() && $actor->isAdmin(), 403);

        return $actor;
    }

    public static function settingOrDefaults(): array
    {
        $s = MailSenderSetting::current();
        $d = MailSenderSetting::defaults();
        if (! $s) {
            return $d + ['has_password' => false, 'is_ready' => false, 'password_set_at' => null];
        }

        return [
            'username' => $s->username,
            'host' => $s->host,
            'port' => (int) $s->port,
            'encryption' => $s->encryption,
            'has_password' => $s->hasPassword(),
            'is_ready' => $s->isReadyToSend(),
            'password_set_at' => $s->password_set_at,
        ];
    }

    /**
     * Salva remetente/host/porta. Senha em branco NÃO apaga a existente.
     * $password null/'' = manter; string não vazia = gravar; $clearPassword=true = remover.
     */
    public static function saveSettings(?User $actor, string $username, string $host, int $port, ?string $password = null, bool $clearPassword = false): MailSenderSetting
    {
        $actor = self::mailAdmin($actor);
        $username = mb_strtolower(trim($username));
        $host = trim($host);
        if ($username !== MailSenderSetting::ALLOWED_USERNAME) {
            throw ValidationException::withMessages(['smtpUsername' => 'O remetente deve ser exatamente '.MailSenderSetting::ALLOWED_USERNAME.'.']);
        }
        if ($host !== MailSenderSetting::DEFAULT_HOST) {
            throw ValidationException::withMessages(['smtpHost' => 'O host deve ser '.MailSenderSetting::DEFAULT_HOST.'.']);
        }
        if ($port !== MailSenderSetting::DEFAULT_PORT) {
            throw ValidationException::withMessages(['smtpPort' => 'A porta deve ser '.MailSenderSetting::DEFAULT_PORT.' (TLS).']);
        }
        if ($clearPassword && $password !== null && $password !== '') {
            throw ValidationException::withMessages(['smtpPassword' => 'Não é possível gravar e remover a senha ao mesmo tempo.']);
        }

        return DB::transaction(function () use ($actor, $username, $host, $port, $password, $clearPassword) {
            $s = MailSenderSetting::current() ?? new MailSenderSetting;
            $s->username = $username;
            $s->host = $host;
            $s->port = $port;
            $s->encryption = MailSenderSetting::DEFAULT_ENCRYPTION;
            $s->updated_by = $actor->id;
            if ($clearPassword) {
                $s->password = null;
                $s->password_set_at = null;
            } elseif ($password !== null && $password !== '') {
                if (mb_strlen($password) > 200) {
                    throw ValidationException::withMessages(['smtpPassword' => 'Senha inválida.']);
                }
                $s->password = $password;
                $s->password_set_at = now();
            }
            $s->save();

            return $s->fresh();
        });
    }

    public static function clearPassword(?User $actor): MailSenderSetting
    {
        $actor = self::mailAdmin($actor);
        $s = MailSenderSetting::current();
        if (! $s) {
            throw ValidationException::withMessages(['smtp' => 'Nenhuma configuração salva.']);
        }

        return self::saveSettings($actor, $s->username, $s->host, (int) $s->port, null, true);
    }

    /** Aplica mailer SMTP só em memória para esta execução. */
    public static function configureRuntimeMailer(MailSenderSetting $setting): void
    {
        if (! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['smtp' => 'Remetente SMTP incompleto: salve a senha de aplicativo antes de enviar.']);
        }
        // Lê a senha via cast encrypted; não logar.
        $password = $setting->password;
        Config::set('mail.mailers.'.self::MAILER_NAME, [
            'transport' => 'smtp',
            'host' => $setting->host,
            'port' => $setting->port,
            'encryption' => $setting->encryption,
            'username' => $setting->username,
            'password' => $password,
            'timeout' => self::TRANSPORT_TIMEOUT,
            'scheme' => null,
            'url' => null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]);
    }

    /**
     * Disparo explícito: regenera o token (garante link válido), reivindica entrega atomicamente e enfileira.
     * Não marca o convite como aceito. Não envia ao criar/regenerar automaticamente.
     *
     * @return array{0: UserInvitation, 1: string} convite + token em texto (para o admin copiar se quiser)
     */
    public static function queueInvitationEmail(?User $actor, int $invitationId, ?string $knownToken = null): array
    {
        $actor = self::anyAdmin($actor);
        $setting = MailSenderSetting::current();
        if (! $setting || ! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['inviteMail' => 'Configure o remetente SMTP (com senha) antes de enviar o convite por e-mail.']);
        }

        return DB::transaction(function () use ($actor, $invitationId, $knownToken) {
            $inv = UserInvitation::whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($inv->status() !== 'pending') {
                throw ValidationException::withMessages(['inviteMail' => 'Só é possível enviar e-mail de convite pendente.']);
            }
            if ($inv->email_delivery_status === 'queued') {
                throw ValidationException::withMessages(['inviteMail' => 'Já existe um envio deste convite na fila. Aguarde o resultado.']);
            }

            $token = null;
            if ($knownToken && hash_equals($inv->token_hash, UserInvitation::hashToken($knownToken))) {
                $token = $knownToken;
            } else {
                $token = Str::random(64);
                $inv->token_hash = UserInvitation::hashToken($token);
                $inv->expires_at = now()->addDays(UserInvitation::VALID_DAYS);
                $inv->revoked_at = null;
            }

            $dispatch = Str::random(64);
            $inv->email_delivery_status = 'queued';
            $inv->email_dispatch_token = $dispatch;
            $inv->email_attempts = (int) $inv->email_attempts + 1;
            $inv->email_queued_at = now();
            $inv->email_failed_at = null;
            $inv->email_last_error = null;
            $inv->save();

            SendUserInvitationEmail::dispatch($inv->id, $token, $dispatch, $actor->id);

            return [$inv->fresh(), $token];
        });
    }

    /** Envia de fato (chamado pelo job). Override só aqui. */
    public static function deliver(int $invitationId, string $token, string $dispatchToken): void
    {
        $setting = MailSenderSetting::current();
        if (! $setting || ! $setting->isReadyToSend()) {
            self::markFailed($invitationId, $dispatchToken, 'Remetente SMTP não configurado.');

            return;
        }

        $inv = UserInvitation::whereKey($invitationId)->first();
        if (! $inv || $inv->email_dispatch_token !== $dispatchToken || $inv->email_delivery_status !== 'queued') {
            return; // claim inválido / já processado
        }
        if ($inv->status() !== 'pending') {
            self::markFailed($invitationId, $dispatchToken, 'Convite não está mais pendente.');

            return;
        }
        if (! hash_equals($inv->token_hash, UserInvitation::hashToken($token))) {
            self::markFailed($invitationId, $dispatchToken, 'Token do convite inválido para este envio.');

            return;
        }

        self::configureRuntimeMailer($setting);
        $link = route('invite.accept', ['token' => $token]);

        try {
            Mail::mailer(self::MAILER_NAME)
                ->to($inv->email)
                ->send(new UserInvitationMail(
                    inviteeName: $inv->name,
                    acceptUrl: $link,
                    expiresAt: $inv->expires_at,
                    fromAddress: $setting->username,
                    fromName: config('app.name', 'App'),
                ));
        } catch (\Throwable $e) {
            self::markFailed($invitationId, $dispatchToken, 'Falha ao enviar e-mail.');
            report($e);

            throw $e; // permite retry do queue
        }

        DB::transaction(function () use ($invitationId, $dispatchToken) {
            $row = UserInvitation::whereKey($invitationId)->lockForUpdate()->first();
            if (! $row || $row->email_dispatch_token !== $dispatchToken || $row->email_delivery_status !== 'queued') {
                return;
            }
            $row->forceFill([
                'email_delivery_status' => 'sent',
                'email_sent_at' => now(),
                'email_last_error' => null,
            ])->save();
        });
    }

    public static function markFailed(int $invitationId, string $dispatchToken, string $genericError): void
    {
        DB::transaction(function () use ($invitationId, $dispatchToken, $genericError) {
            $row = UserInvitation::whereKey($invitationId)->lockForUpdate()->first();
            if (! $row || $row->email_dispatch_token !== $dispatchToken) {
                return;
            }
            if ($row->email_delivery_status === 'sent') {
                return;
            }
            $row->forceFill([
                'email_delivery_status' => 'failed',
                'email_failed_at' => now(),
                'email_last_error' => mb_substr($genericError, 0, 190),
            ])->save();
        });
    }

    /**
     * Autoteste: envia somente para o próprio endereço do remetente configurado.
     * Exige senha. Não convida terceiros.
     */
    public static function sendSelfTest(?User $actor): void
    {
        $actor = self::mailAdmin($actor);
        $setting = MailSenderSetting::current();
        if (! $setting || ! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['smtpTest' => 'Salve a senha de aplicativo antes do teste.']);
        }
        self::configureRuntimeMailer($setting);
        Mail::mailer(self::MAILER_NAME)
            ->to($setting->username)
            ->send(new MailSenderSelfTestMail(
                fromAddress: $setting->username,
                fromName: config('app.name', 'App'),
            ));
    }
}

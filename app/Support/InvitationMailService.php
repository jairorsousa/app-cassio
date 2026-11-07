<?php

namespace App\Support;

use App\Jobs\SendUserInvitationEmail;
use App\Mail\MailSenderSelfTestMail;
use App\Mail\UserInvitationMail;
use App\Models\GmailSendCredential;
use App\Models\MailSenderSetting;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\GmailSend\GmailSendAuthException;
use App\Support\GmailSend\GmailSendOAuthService;
use App\Support\GmailSend\GmailSendRetryableException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Envio de convites: transporte preferido google-oauth (Gmail API).
 * SMTP legado permanece no banco/UI recolhida, mas NÃO é usado em silencio se OAuth não estiver pronto.
 */
class InvitationMailService
{
    public const MAILER_NAME = 'invitation_smtp';

    public const TRANSPORT_TIMEOUT = 15;

    public const MSG_AUTH = 'O Google recusou a autenticação SMTP (credenciais ou permissão do serviço). Nenhum e-mail foi entregue. Use o link manual do convite ou revise a senha de aplicativo e teste de novo.';

    public const MSG_TRANSPORT = 'Não foi possível concluir o envio pelo servidor de e-mail. Nenhum e-mail foi entregue. Tente de novo mais tarde ou use o link manual do convite.';

    public const MSG_GENERIC = 'Falha ao enviar e-mail. Nenhum e-mail foi entregue. Use o link manual do convite.';

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

    public static function oauth(): GmailSendOAuthService
    {
        return app(GmailSendOAuthService::class);
    }

    /** Pronto para envio real = OAuth Gmail conectado (sem fallback SMTP). */
    public static function isReadyToSend(): bool
    {
        return self::oauth()->isReady();
    }

    public static function settingOrDefaults(): array
    {
        $s = MailSenderSetting::current();
        $d = MailSenderSetting::defaults();
        $oauth = self::oauth()->status();
        $smtp = [
            'username' => $s->username ?? $d['username'],
            'host' => $s->host ?? $d['host'],
            'port' => (int) ($s->port ?? $d['port']),
            'encryption' => $s->encryption ?? $d['encryption'],
            'has_password' => $s?->hasPassword() ?? false,
            'password_format_ok' => $s?->passwordFormatOk() ?? false,
            'password_set_at' => $s?->password_set_at,
            'last_self_test_status' => $s?->last_self_test_status,
            'last_self_test_at' => $s?->last_self_test_at,
            'last_self_test_error' => $s?->last_self_test_error,
            'legacy_only' => true,
        ];

        return $smtp + [
            'is_ready' => self::isReadyToSend(),
            'transport' => $oauth['transport'],
            'oauth' => $oauth,
        ];
    }

    public static function classifyTransportFailure(\Throwable $e): array
    {
        if ($e instanceof GmailSendAuthException) {
            return ['kind' => 'auth', 'message' => GmailSendOAuthService::MSG_AUTH, 'retryable' => false];
        }
        if ($e instanceof GmailSendRetryableException) {
            return ['kind' => 'transport', 'message' => GmailSendOAuthService::MSG_RETRY, 'retryable' => true];
        }
        $hay = strtolower(class_basename($e).' '.$e->getMessage());
        $authHints = ['535', 'badcredentials', 'authentication failed', 'authentica', 'username and password not accepted', 'invalid credentials', '5.7.8', '5.7.9', 'not authorized', 'gmail_api_auth', 'refresh_failed'];
        foreach ($authHints as $hint) {
            if (str_contains($hay, $hint)) {
                return ['kind' => 'auth', 'message' => self::MSG_AUTH, 'retryable' => false];
            }
        }
        if ($e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface) {
            return ['kind' => 'transport', 'message' => self::MSG_TRANSPORT, 'retryable' => true];
        }

        return ['kind' => 'other', 'message' => self::MSG_GENERIC, 'retryable' => true];
    }

    public static function logTransportFailure(string $context, \Throwable $e, array $classified): void
    {
        Log::warning('invitation_mail_failure', [
            'context' => $context,
            'kind' => $classified['kind'],
            'exception' => class_basename($e),
            'retryable' => $classified['retryable'],
            'transport' => self::oauth()->isReady() ? 'google-oauth' : 'none',
        ]);
    }

    public static function recordSelfTestResult(string $status, ?string $safeError = null): void
    {
        self::oauth()->recordSelfTest($status, $safeError);
        // SMTP legado: não grava mais como fonte de verdade do teste.
    }

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
                $s->last_self_test_status = null;
                $s->last_self_test_at = null;
                $s->last_self_test_error = null;
            } elseif ($password !== null && $password !== '') {
                if (mb_strlen($password) > 200) {
                    throw ValidationException::withMessages(['smtpPassword' => 'Senha inválida.']);
                }
                $normalized = MailSenderSetting::normalizeAppPassword($password);
                if (! preg_match('/^[a-z]{16}$/', $normalized)) {
                    throw ValidationException::withMessages(['smtpPassword' => 'Use a senha de aplicativo do Google (16 letras; os espaços entre grupos são opcionais). Não use a senha normal da conta.']);
                }
                $s->password = $normalized;
                $s->password_set_at = now();
                $s->last_self_test_status = null;
                $s->last_self_test_at = null;
                $s->last_self_test_error = null;
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

    public static function configureRuntimeMailer(MailSenderSetting $setting): void
    {
        if (! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['smtp' => 'Remetente SMTP incompleto.']);
        }
        $password = MailSenderSetting::normalizeAppPassword((string) $setting->password);
        Config::set('mail.mailers.'.self::MAILER_NAME, [
            'transport' => 'smtp',
            'host' => $setting->host,
            'port' => $setting->port,
            'encryption' => $setting->encryption,
            'username' => $setting->username,
            'password' => $password,
            'timeout' => self::TRANSPORT_TIMEOUT,
            'scheme' => 'smtp',
            'url' => null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]);
    }

    /** @return array{0: UserInvitation, 1: string} */
    public static function queueInvitationEmail(?User $actor, int $invitationId, ?string $knownToken = null): array
    {
        $actor = self::anyAdmin($actor);
        if (! self::isReadyToSend()) {
            throw ValidationException::withMessages(['inviteMail' => GmailSendOAuthService::MSG_NOT_CONNECTED.' O envio por senha de aplicativo está desativado.']);
        }

        return DB::transaction(function () use ($actor, $invitationId, $knownToken) {
            $inv = UserInvitation::whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($inv->status() !== 'pending') {
                throw ValidationException::withMessages(['inviteMail' => 'Só é possível enviar e-mail de convite pendente.']);
            }
            if ($inv->email_delivery_status === 'queued') {
                throw ValidationException::withMessages(['inviteMail' => 'Já existe um envio deste convite na fila. Aguarde o resultado.']);
            }

            if ($knownToken && hash_equals($inv->token_hash, UserInvitation::hashToken($knownToken))) {
                $token = $knownToken;
            } else {
                $token = Str::random(64);
                $inv->token_hash = UserInvitation::hashToken($token);
                $inv->token_encrypted = \Illuminate\Support\Facades\Crypt::encryptString($token);
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

    public static function deliver(int $invitationId, string $token, string $dispatchToken): void
    {
        if (! self::isReadyToSend()) {
            self::markFailed($invitationId, $dispatchToken, GmailSendOAuthService::MSG_NOT_CONNECTED);

            return;
        }

        $inv = UserInvitation::whereKey($invitationId)->first();
        if (! $inv || $inv->email_dispatch_token !== $dispatchToken || $inv->email_delivery_status !== 'queued') {
            return;
        }
        if ($inv->status() !== 'pending') {
            self::markFailed($invitationId, $dispatchToken, 'Convite não está mais pendente.');

            return;
        }
        if (! hash_equals($inv->token_hash, UserInvitation::hashToken($token))) {
            self::markFailed($invitationId, $dispatchToken, 'Token do convite inválido para este envio.');

            return;
        }

        $link = route('invite.accept', ['token' => $token]);
        $from = (string) config('gmail-send.allowed_account');
        $html = '<p>Olá, '.e($inv->name).'.</p><p>Você recebeu um convite. Use o link (válido até '
            .e($inv->expires_at->timezone('America/Fortaleza')->format('d/m/Y H:i')).' Fortaleza):</p><p><a href="'.e($link).'">'.e($link).'</a></p>';

        try {
            self::oauth()->sendHtml(
                to: $inv->email,
                subject: 'Convite para acessar '.config('app.name', 'o sistema'),
                htmlBody: $html,
                from: $from,
            );
        } catch (\Throwable $e) {
            $info = self::classifyTransportFailure($e);
            self::logTransportFailure('invitation_deliver', $e, $info);
            self::markFailed($invitationId, $dispatchToken, $info['message']);
            if ($info['retryable']) {
                throw $e;
            }

            return;
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

    /** Autoteste síncrono via Gmail API (OAuth). Sem SMTP. */
    public static function sendSelfTest(?User $actor): void
    {
        $actor = self::mailAdmin($actor);
        if (! self::isReadyToSend()) {
            throw ValidationException::withMessages(['smtpTest' => GmailSendOAuthService::MSG_NOT_CONNECTED]);
        }
        $from = (string) config('gmail-send.allowed_account');
        try {
            self::oauth()->sendHtml(
                to: $from,
                subject: 'Teste de remetente Gmail (convites)',
                htmlBody: '<p>Este é um e-mail de teste do remetente Gmail OAuth. Nenhum convite foi criado.</p>',
                from: $from,
            );
        } catch (\Throwable $e) {
            $info = self::classifyTransportFailure($e);
            self::logTransportFailure('gmail_self_test', $e, $info);
            self::recordSelfTestResult('failed', $info['message']);
            throw ValidationException::withMessages(['smtpTest' => $info['message']]);
        }
        self::recordSelfTestResult('ok', null);
    }
}

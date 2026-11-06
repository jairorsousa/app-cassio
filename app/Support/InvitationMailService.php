<?php

namespace App\Support;

use App\Jobs\SendUserInvitationEmail;
use App\Mail\MailSenderSelfTestMail;
use App\Mail\UserInvitationMail;
use App\Models\MailSenderSetting;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Configuração SMTP do remetente de convites e disparo explícito de e-mail.
 * Override de transport só no momento do envio — não altera MAIL_MAILER global nem bootstrap.
 */
class InvitationMailService
{
    public const MAILER_NAME = 'invitation_smtp';

    public const TRANSPORT_TIMEOUT = 15;

    public const MSG_AUTH = 'O Google recusou a autenticação SMTP (credenciais ou permissão do serviço). Nenhum e-mail foi entregue. Use o link manual do convite ou revise a senha de aplicativo e teste de novo.';

    public const MSG_TRANSPORT = 'Não foi possível concluir o envio pelo servidor de e-mail. Nenhum e-mail foi entregue. Tente de novo mais tarde ou use o link manual do convite.';

    public const MSG_GENERIC = 'Falha ao enviar e-mail. Nenhum e-mail foi entregue. Use o link manual do convite.';

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
            return $d + [
                'has_password' => false,
                'password_format_ok' => false,
                'is_ready' => false,
                'password_set_at' => null,
                'last_self_test_status' => null,
                'last_self_test_at' => null,
                'last_self_test_error' => null,
            ];
        }

        return [
            'username' => $s->username,
            'host' => $s->host,
            'port' => (int) $s->port,
            'encryption' => $s->encryption,
            'has_password' => $s->hasPassword(),
            'password_format_ok' => $s->passwordFormatOk(),
            'is_ready' => $s->isReadyToSend(),
            'password_set_at' => $s->password_set_at,
            'last_self_test_status' => $s->last_self_test_status,
            'last_self_test_at' => $s->last_self_test_at,
            'last_self_test_error' => $s->last_self_test_error,
        ];
    }

    /**
     * Classifica falha de transporte sem expor usuário/senha nem texto cru do provedor na UI.
     *
     * @return array{kind: string, message: string, retryable: bool}
     */
    public static function classifyTransportFailure(\Throwable $e): array
    {
        $hay = strtolower(class_basename($e).' '.$e->getMessage());
        $authHints = ['535', 'badcredentials', 'authentication failed', 'authentica', 'username and password not accepted', 'invalid credentials', '5.7.8', '5.7.9', 'not authorized'];
        foreach ($authHints as $hint) {
            if (str_contains($hay, $hint)) {
                return ['kind' => 'auth', 'message' => self::MSG_AUTH, 'retryable' => false];
            }
        }
        if ($e instanceof TransportExceptionInterface) {
            return ['kind' => 'transport', 'message' => self::MSG_TRANSPORT, 'retryable' => true];
        }

        return ['kind' => 'other', 'message' => self::MSG_GENERIC, 'retryable' => true];
    }

    /** Log seguro: só tipo/classe, sem mensagem do provedor (pode trazer username). */
    public static function logTransportFailure(string $context, \Throwable $e, array $classified): void
    {
        Log::warning('invitation_smtp_failure', [
            'context' => $context,
            'kind' => $classified['kind'],
            'exception' => class_basename($e),
            'retryable' => $classified['retryable'],
        ]);
    }

    public static function recordSelfTestResult(string $status, ?string $safeError = null): void
    {
        $s = MailSenderSetting::current();
        if (! $s) {
            return;
        }
        $s->forceFill([
            'last_self_test_status' => $status,
            'last_self_test_at' => now(),
            'last_self_test_error' => $safeError !== null ? mb_substr($safeError, 0, 190) : null,
        ])->save();
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
                $s->last_self_test_status = null;
                $s->last_self_test_at = null;
                $s->last_self_test_error = null;
            } elseif ($password !== null && $password !== '') {
                if (mb_strlen($password) > 200) {
                    throw ValidationException::withMessages(['smtpPassword' => 'Senha inválida.']);
                }
                // Google mostra a senha em grupos; espaços ASCII são opcionais e removidos.
                $normalized = MailSenderSetting::normalizeAppPassword($password);
                if (! preg_match('/^[a-z]{16}$/', $normalized)) {
                    throw ValidationException::withMessages(['smtpPassword' => 'Use a senha de aplicativo do Google (16 letras; os espaços entre grupos são opcionais). Não use a senha normal da conta.']);
                }
                $s->password = $normalized;
                $s->password_set_at = now();
                // Nova senha invalida o último teste (ainda não confirma entrega).
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

    /** Aplica mailer SMTP só em memória para esta execução. */
    public static function configureRuntimeMailer(MailSenderSetting $setting): void
    {
        if (! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['smtp' => 'Remetente SMTP incompleto: salve a senha de aplicativo antes de enviar.']);
        }
        // Laravel 12 resolve scheme smtp (STARTTLS) para porta 587; encryption legado é informativo.
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

    /**
     * @return array{0: UserInvitation, 1: string}
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

    /** Envia de fato (job). Falha de autenticação: marca failed e NÃO relança (sem retry inútil). */
    public static function deliver(int $invitationId, string $token, string $dispatchToken): void
    {
        $setting = MailSenderSetting::current();
        if (! $setting || ! $setting->isReadyToSend()) {
            self::markFailed($invitationId, $dispatchToken, 'Remetente SMTP não configurado.');

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
            $info = self::classifyTransportFailure($e);
            self::logTransportFailure('invitation_deliver', $e, $info);
            self::markFailed($invitationId, $dispatchToken, $info['message']);
            if ($info['retryable']) {
                throw $e;
            }

            return; // auth permanente: worker não retenta
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
     * Autoteste síncrono (Livewire): captura falha de transporte e devolve ValidationException amigável (sem 500).
     */
    public static function sendSelfTest(?User $actor): void
    {
        $actor = self::mailAdmin($actor);
        $setting = MailSenderSetting::current();
        if (! $setting || ! $setting->isReadyToSend()) {
            throw ValidationException::withMessages(['smtpTest' => 'Salve a senha de aplicativo antes do teste.']);
        }

        try {
            self::configureRuntimeMailer($setting);
            Mail::mailer(self::MAILER_NAME)
                ->to($setting->username)
                ->send(new MailSenderSelfTestMail(
                    fromAddress: $setting->username,
                    fromName: config('app.name', 'App'),
                ));
        } catch (\Throwable $e) {
            $info = self::classifyTransportFailure($e);
            self::logTransportFailure('smtp_self_test', $e, $info);
            self::recordSelfTestResult('failed', $info['message']);
            throw ValidationException::withMessages(['smtpTest' => $info['message']]);
        }

        self::recordSelfTestResult('ok', null);
    }
}

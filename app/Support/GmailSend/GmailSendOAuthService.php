<?php

namespace App\Support\GmailSend;

use App\Models\GmailSendCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GmailSendOAuthService
{
    public const SESSION_KEY = 'gmail_send_oauth';

    public const MSG_AUTH = 'A conexão Gmail precisa ser refeita (autorização expirada ou revogada). Nenhum e-mail foi entregue. Use o link manual do convite.';

    public const MSG_RETRY = 'Não foi possível enviar pelo Gmail agora. Nenhum e-mail foi entregue. Tente de novo mais tarde ou use o link manual.';

    public const MSG_NOT_CONNECTED = 'Conecte o Gmail (somente envio de convites) antes de enviar e-mail.';

    public function __construct(private GmailSendClient $client) {}

    public function configured(): bool
    {
        return (bool) config('gmail-send.enabled')
            && filled(config('gmail-send.client_id'))
            && filled(config('gmail-send.client_secret'))
            && filled(config('gmail-send.redirect_uri'));
    }

    public function assertMailAdmin(?User $actor): User
    {
        abort_unless($actor && $actor->isActive() && $actor->isAdmin() && (int) $actor->id === 1, 403);

        return $actor;
    }

    public function status(): array
    {
        $cred = GmailSendCredential::current();
        $allowed = (string) config('gmail-send.allowed_account');

        return [
            'configured' => $this->configured(),
            'connected' => $cred !== null,
            'account_email' => $cred?->account_email,
            'scopes' => $cred?->scopes ?? [],
            'connected_at' => $cred?->connected_at,
            'is_ready' => $this->isReady(),
            'allowed_account' => $allowed,
            'redirect_uri' => (string) config('gmail-send.redirect_uri'),
            'last_self_test_status' => $cred?->last_self_test_status,
            'last_self_test_at' => $cred?->last_self_test_at,
            'last_self_test_error' => $cred?->last_self_test_error,
            'transport' => $this->isReady() ? 'google-oauth' : 'none',
        ];
    }

    public function isReady(): bool
    {
        $cred = GmailSendCredential::current();
        if (! $cred || ! $this->configured()) {
            return false;
        }
        $allowed = mb_strtolower((string) config('gmail-send.allowed_account'));
        if (mb_strtolower($cred->account_email) !== $allowed) {
            return false;
        }
        $refresh = $cred->getRawOriginal('refresh_token');

        return is_string($refresh) && $refresh !== '';
    }

    /** @return array{state:string,url:string} */
    public function beginConnect(User $actor): array
    {
        $this->assertMailAdmin($actor);
        if (! $this->configured()) {
            throw ValidationException::withMessages(['gmailOAuth' => 'Cliente OAuth do Gmail não está configurado no servidor.']);
        }
        $state = Str::random(64);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        session([self::SESSION_KEY => [
            'state' => $state,
            'verifier' => $verifier,
            'at' => now()->timestamp,
            'uid' => $actor->id,
        ]]);

        $params = [
            'client_id' => config('gmail-send.client_id'),
            'redirect_uri' => config('gmail-send.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('gmail-send.scopes')),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
        ];

        return [
            'state' => $state,
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ];
    }

    public function completeCallback(User $actor, string $state, string $code): GmailSendCredential
    {
        $this->assertMailAdmin($actor);
        $saved = session()->pull(self::SESSION_KEY);
        if (! is_array($saved)
            || ! hash_equals((string) ($saved['state'] ?? ''), $state)
            || (int) ($saved['uid'] ?? 0) !== (int) $actor->id
            || empty($saved['verifier'])
            || now()->timestamp - (int) ($saved['at'] ?? 0) > 600
        ) {
            throw ValidationException::withMessages(['gmailOAuth' => 'Estado OAuth inválido ou expirado. Conecte de novo.']);
        }
        if ($code === '' || strlen($code) > 2048) {
            throw ValidationException::withMessages(['gmailOAuth' => 'Código de autorização inválido.']);
        }

        try {
            $token = $this->client->exchangeCode($code, (string) $saved['verifier'], (string) config('gmail-send.redirect_uri'));
        } catch (\Throwable $e) {
            Log::warning('gmail_send_oauth_exchange_failed', ['type' => class_basename($e)]);
            throw ValidationException::withMessages(['gmailOAuth' => 'Não foi possível concluir a autorização com o Google. Tente de novo.']);
        }

        $scopes = $this->parseScopes((string) ($token['scope'] ?? ''));
        $this->assertScopesAllowed($scopes);

        $refresh = $token['refresh_token'] ?? null;
        $existing = GmailSendCredential::current();
        if ((! is_string($refresh) || $refresh === '') && $existing) {
            $refresh = $existing->refresh_token; // Google às vezes omite se já havia grant
        }
        if (! is_string($refresh) || $refresh === '') {
            throw ValidationException::withMessages(['gmailOAuth' => 'O Google não devolveu refresh token. Revogue o acesso do app em myaccount.google.com/permissions e conecte de novo com consentimento.']);
        }

        try {
            $email = mb_strtolower($this->client->accountEmail((string) $token['access_token']));
        } catch (\Throwable $e) {
            Log::warning('gmail_send_oauth_userinfo_failed', ['type' => class_basename($e)]);
            throw ValidationException::withMessages(['gmailOAuth' => 'Não foi possível confirmar o e-mail da conta Google.']);
        }

        $allowed = mb_strtolower((string) config('gmail-send.allowed_account'));
        if ($email !== $allowed) {
            throw ValidationException::withMessages(['gmailOAuth' => 'Conecte exatamente a conta '.$allowed.'. Conta autorizada foi recusada.']);
        }

        return DB::transaction(function () use ($actor, $token, $refresh, $email, $scopes) {
            $row = GmailSendCredential::current() ?? new GmailSendCredential(['provider' => GmailSendCredential::PROVIDER]);
            $row->fill([
                'provider' => GmailSendCredential::PROVIDER,
                'account_email' => $email,
                'access_token' => (string) $token['access_token'],
                'refresh_token' => $refresh,
                'expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600))),
                'scopes' => $scopes,
                'connected_by_user_id' => $actor->id,
                'connected_at' => now(),
                'last_self_test_status' => null,
                'last_self_test_at' => null,
                'last_self_test_error' => null,
            ]);
            $row->save();

            return $row->fresh();
        });
    }

    public function disconnect(User $actor): void
    {
        $this->assertMailAdmin($actor);
        session()->forget(self::SESSION_KEY);
        GmailSendCredential::query()->where('provider', GmailSendCredential::PROVIDER)->delete();
    }

    /** @return list<string> */
    public function parseScopes(string $scope): array
    {
        if ($scope === '') {
            return array_values(config('gmail-send.scopes'));
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }

    /** @param list<string> $scopes */
    public function assertScopesAllowed(array $scopes): void
    {
        $required = (string) config('gmail-send.required_mail_scope');
        if (! in_array($required, $scopes, true)) {
            throw ValidationException::withMessages(['gmailOAuth' => 'A autorização não incluiu permissão de envio Gmail (gmail.send).']);
        }
        foreach (config('gmail-send.forbidden_mail_scopes', []) as $bad) {
            if (in_array($bad, $scopes, true)) {
                throw ValidationException::withMessages(['gmailOAuth' => 'Escopo Gmail amplo demais foi concedido. Revogue e conecte só com envio de convites.']);
            }
        }
    }

    public function validAccessToken(): string
    {
        if (! $this->isReady()) {
            throw ValidationException::withMessages(['inviteMail' => self::MSG_NOT_CONNECTED]);
        }
        $cred = GmailSendCredential::current();
        if (! $cred->accessExpired()) {
            return (string) $cred->access_token;
        }
        try {
            $data = $this->client->refresh((string) $cred->refresh_token);
        } catch (\Throwable $e) {
            Log::warning('gmail_send_refresh_failed', ['type' => class_basename($e)]);
            throw new GmailSendAuthException('refresh_failed');
        }
        $cred->forceFill([
            'access_token' => (string) $data['access_token'],
            'expires_at' => now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600))),
        ])->save();
        if (! empty($data['scope'])) {
            $scopes = $this->parseScopes((string) $data['scope']);
            try {
                $this->assertScopesAllowed($scopes);
                $cred->forceFill(['scopes' => $scopes])->save();
            } catch (ValidationException) {
                throw new GmailSendAuthException('scope_lost');
            }
        }

        return (string) $cred->fresh()->access_token;
    }

    public function sendHtml(string $to, string $subject, string $htmlBody, string $from): void
    {
        $token = $this->validAccessToken();
        $raw = $this->buildRfc2822($from, $to, $subject, $htmlBody);
        $this->client->sendRaw($token, $raw);
    }

    public function buildRfc2822(string $from, string $to, string $subject, string $htmlBody): string
    {
        $from = $this->headerSafe($from);
        $to = $this->headerSafe($to);
        $subject = $this->headerSafe($this->encodeSubject($subject));
        $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $body = str_replace("\n", "\r\n", $body);

        return "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}";
    }

    private function headerSafe(string $v): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $v));
    }

    private function encodeSubject(string $subject): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $subject)) {
            return $subject;
        }

        return '=?UTF-8?B?'.base64_encode($subject).'?=';
    }

    public function recordSelfTest(string $status, ?string $error = null): void
    {
        $cred = GmailSendCredential::current();
        if (! $cred) {
            return;
        }
        $cred->forceFill([
            'last_self_test_status' => $status,
            'last_self_test_at' => now(),
            'last_self_test_error' => $error !== null ? mb_substr($error, 0, 190) : null,
        ])->save();
    }

    public function classify(\Throwable $e): array
    {
        if ($e instanceof GmailSendAuthException || $e instanceof ValidationException) {
            return ['kind' => 'auth', 'message' => self::MSG_AUTH, 'retryable' => false];
        }
        if ($e instanceof GmailSendRetryableException) {
            return ['kind' => 'transport', 'message' => self::MSG_RETRY, 'retryable' => true];
        }

        return ['kind' => 'other', 'message' => self::MSG_RETRY, 'retryable' => true];
    }
}

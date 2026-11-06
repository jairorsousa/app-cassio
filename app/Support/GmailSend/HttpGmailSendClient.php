<?php

namespace App\Support\GmailSend;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpGmailSendClient implements GmailSendClient
{
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('gmail-send.client_id'),
            'client_secret' => config('gmail-send.client_secret'),
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('oauth_token_exchange_failed');
        }
        $data = $res->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('oauth_token_exchange_invalid');
        }

        return $data;
    }

    public function refresh(string $refreshToken): array
    {
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('gmail-send.client_id'),
            'client_secret' => config('gmail-send.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('oauth_refresh_failed');
        }
        $data = $res->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('oauth_refresh_invalid');
        }

        return $data;
    }

    public function accountEmail(string $accessToken): string
    {
        $res = Http::withToken($accessToken)->timeout(15)->get('https://www.googleapis.com/oauth2/v2/userinfo');
        if (! $res->successful()) {
            throw new RuntimeException('oauth_userinfo_failed');
        }
        $email = mb_strtolower(trim((string) $res->json('email')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('oauth_userinfo_no_email');
        }

        return $email;
    }

    public function sendRaw(string $accessToken, string $rfc2822): void
    {
        $raw = rtrim(strtr(base64_encode($rfc2822), '+/', '-_'), '=');
        $res = Http::withToken($accessToken)
            ->timeout(20)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
        if ($res->successful()) {
            return;
        }
        $status = $res->status();
        if ($status === 401 || $status === 403) {
            throw new GmailSendAuthException('gmail_api_auth');
        }
        if ($status === 429) {
            throw new GmailSendRetryableException('gmail_api_rate');
        }
        throw new GmailSendRetryableException('gmail_api_error');
    }
}

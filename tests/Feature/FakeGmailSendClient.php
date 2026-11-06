<?php

namespace Tests\Feature;

use App\Support\GmailSend\GmailSendAuthException;
use App\Support\GmailSend\GmailSendClient;
use App\Support\GmailSend\GmailSendRetryableException;

class FakeGmailSendClient implements GmailSendClient
{
    public array $tokenResponse = [
        'access_token' => 'access-test-token',
        'refresh_token' => 'refresh-test-token',
        'expires_in' => 3600,
        'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
        'token_type' => 'Bearer',
    ];

    public string $email = 'cassio@cassiomota.com';

    public bool $exchangeFails = false;

    public bool $refreshFails = false;

    public bool $userinfoFails = false;

    public ?string $sendFail = null; // auth|rate|error|null

    public array $sent = [];

    public ?string $lastVerifier = null;

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $this->lastVerifier = $codeVerifier;
        if ($this->exchangeFails) {
            throw new \RuntimeException('oauth_token_exchange_failed');
        }

        return $this->tokenResponse;
    }

    public function refresh(string $refreshToken): array
    {
        if ($this->refreshFails) {
            throw new \RuntimeException('oauth_refresh_failed');
        }

        return [
            'access_token' => 'access-refreshed',
            'expires_in' => 3600,
            'scope' => $this->tokenResponse['scope'] ?? 'openid email https://www.googleapis.com/auth/gmail.send',
        ];
    }

    public function accountEmail(string $accessToken): string
    {
        if ($this->userinfoFails) {
            throw new \RuntimeException('oauth_userinfo_failed');
        }

        return $this->email;
    }

    public function sendRaw(string $accessToken, string $rfc2822): void
    {
        if ($this->sendFail === 'auth') {
            throw new GmailSendAuthException('gmail_api_auth');
        }
        if ($this->sendFail === 'rate') {
            throw new GmailSendRetryableException('gmail_api_rate');
        }
        if ($this->sendFail === 'error') {
            throw new GmailSendRetryableException('gmail_api_error');
        }
        $this->sent[] = ['token_len' => strlen($accessToken), 'raw' => $rfc2822];
    }
}

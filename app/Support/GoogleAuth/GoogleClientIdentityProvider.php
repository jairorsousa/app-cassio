<?php

namespace App\Support\GoogleAuth;

use Google\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleClientIdentityProvider implements IdentityProvider
{
    public function configured(): bool
    {
        return (bool) config('google-auth.enabled')
            && filled(config('google-auth.client_id'))
            && filled(config('google-auth.client_secret'));
    }

    private function client(): Client
    {
        $c = new Client();
        $c->setClientId((string) config('google-auth.client_id'));
        $c->setClientSecret((string) config('google-auth.client_secret'));
        $c->setRedirectUri((string) config('google-auth.redirect_uri'));
        $c->setScopes(['openid', 'email', 'profile']);

        return $c;
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('google-auth.client_id'),
            'redirect_uri' => config('google-auth.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verifiedIdentity(string $code, string $codeVerifier): ?array
    {
        try {
            $client = $this->client();
            $token = $client->fetchAccessTokenWithAuthCode($code, $codeVerifier);
            $idToken = is_array($token) ? ($token['id_token'] ?? null) : null;
            if (! is_string($idToken) || $idToken === '') {
                Log::warning('google_login: troca de código sem id_token');
                return null;
            }
            $payload = $client->verifyIdToken($idToken); // assinatura, aud, iss, exp
            if (! is_array($payload) || ($payload['aud'] ?? null) !== config('google-auth.client_id')) {
                Log::warning('google_login: id_token inválido');
                return null;
            }

            return [
                'sub' => (string) ($payload['sub'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'email_verified' => filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        } catch (Throwable $e) {
            Log::warning('google_login: falha na verificação', ['type' => $e::class]);
            return null;
        }
    }
}

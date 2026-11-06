<?php

namespace App\Support\GoogleAuth;

interface IdentityProvider
{
    public function configured(): bool;

    /** URL de autorização com state e PKCE (S256). */
    public function authorizationUrl(string $state, string $codeChallenge): string;

    /**
     * Troca o código e devolve as claims do ID token já VERIFICADO
     * (assinatura, aud, iss, exp). Retorna null em qualquer falha.
     *
     * @return array{sub:string,email:string,email_verified:bool}|null
     */
    public function verifiedIdentity(string $code, string $codeVerifier): ?array;
}

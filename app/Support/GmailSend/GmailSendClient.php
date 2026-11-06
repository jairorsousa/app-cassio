<?php

namespace App\Support\GmailSend;

/**
 * Gateway HTTP ao Google (OAuth token + Gmail send). Implementação real e fake de teste.
 */
interface GmailSendClient
{
    /**
     * @return array{access_token:string,refresh_token:?string,expires_in?:int,scope?:string,id_token?:string,token_type?:string}
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array;

    /**
     * @return array{access_token:string,expires_in?:int,scope?:string,token_type?:string}
     */
    public function refresh(string $refreshToken): array;

    /** E-mail da conta autorizada (userinfo). */
    public function accountEmail(string $accessToken): string;

    /** Envia mensagem RFC2822 raw (base64url) via users/me/messages/send. */
    public function sendRaw(string $accessToken, string $rfc2822): void;
}

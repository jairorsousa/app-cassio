<?php

namespace App\Support;

/**
 * Verifica assinatura de webhooks Chatwoot (conta).
 *
 * Documentação oficial (Chatwoot User Guide — Verifying webhooks):
 *   X-Chatwoot-Signature: sha256=<hex>
 *   X-Chatwoot-Timestamp: unix seconds
 *   signed_payload = "{timestamp}.{raw_body}"
 *   HMAC-SHA256(webhook_secret, signed_payload)
 *
 * O segredo é o "Secret" exibido ao criar/editar o webhook na UI do Chatwoot
 * (por webhook). Guardar no app em CHATWOOT_WEBHOOK_SECRET — nunca logar.
 */
class ChatwootWebhookSignature
{
    public const HEADER_SIGNATURE = 'X-Chatwoot-Signature';

    public const HEADER_TIMESTAMP = 'X-Chatwoot-Timestamp';

    /**
     * @param  string  $rawBody  Corpo HTTP bruto (JSON sem re-serializar)
     */
    public static function expected(string $secret, string $timestamp, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Aceita "sha256=<hex>" (oficial) ou o hex puro. Comparação em tempo constante.
     */
    public static function isValid(string $secret, string $timestamp, string $rawBody, string $providedSignature): bool
    {
        if ($secret === '' || $timestamp === '' || $providedSignature === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('services.chatwoot.webhook_signature_tolerance', 300);
        if ($tolerance > 0) {
            $age = abs(time() - (int) $timestamp);
            if ($age > $tolerance) {
                return false;
            }
        }

        $expectedHex = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        $provided = trim($providedSignature);
        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $providedHex = substr($provided, 7);
        } else {
            $providedHex = $provided;
        }

        if (strlen($providedHex) !== strlen($expectedHex)) {
            return false;
        }

        return hash_equals($expectedHex, $providedHex);
    }
}

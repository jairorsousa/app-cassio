<?php

namespace App\Http\Controllers;

use App\Support\ChatwootOptOutWebhook;
use App\Support\ChatwootWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook Chatwoot (somente leitura de eventos). Não responde no WhatsApp e não altera conversas.
 *
 * Auth (qualquer um dos dois, se configurado):
 * 1) Assinatura oficial: X-Chatwoot-Signature + X-Chatwoot-Timestamp com CHATWOOT_WEBHOOK_SECRET
 *    (Secret do webhook "Transmissão - SAIR" na UI). Corpo = raw body.
 * 2) Token legado/local: header X-Chatwoot-Webhook-Token / X-Webhook-Token ou query ?token=
 *    com CHATWOOT_WEBHOOK_TOKEN.
 *
 * Sem token e sem secret → 503 (desligado). Assinatura só ativa com secret preenchido.
 * Mesmo path: POST /webhooks/chatwoot — não criar outro.
 */
class ChatwootWebhookController extends Controller
{
    public function __invoke(Request $request, ChatwootOptOutWebhook $handler): JsonResponse
    {
        $token = (string) config('services.chatwoot.webhook_token', '');
        $secret = (string) config('services.chatwoot.webhook_secret', '');

        if ($token === '' && $secret === '') {
            return response()->json(['status' => 'disabled', 'reason' => 'webhook_auth_unset'], 503);
        }

        if (! $this->authorized($request, $token, $secret)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['status' => 'ignored', 'reason' => 'invalid_payload'], 200);
        }

        $result = $handler->handle($payload);

        if (($result['status'] ?? '') === ChatwootOptOutWebhook::RESULT_RATE_LIMITED) {
            return response()->json($result, 429);
        }

        // Eventos irrelevantes e opt-outs processados respondem 200 para o Chatwoot não reenviar.
        return response()->json($result, 200);
    }

    private function authorized(Request $request, string $token, string $secret): bool
    {
        if ($token !== '' && $this->tokenMatches($request, $token)) {
            return true;
        }

        if ($secret !== '' && $this->signatureMatches($request, $secret)) {
            return true;
        }

        return false;
    }

    private function tokenMatches(Request $request, string $configured): bool
    {
        $provided = (string) ($request->header('X-Chatwoot-Webhook-Token')
            ?: $request->header('X-Webhook-Token')
            ?: $request->query('token', ''));

        return $provided !== '' && hash_equals($configured, $provided);
    }

    private function signatureMatches(Request $request, string $secret): bool
    {
        $signature = (string) $request->header(ChatwootWebhookSignature::HEADER_SIGNATURE, '');
        $timestamp = (string) $request->header(ChatwootWebhookSignature::HEADER_TIMESTAMP, '');
        // Raw body obrigatório — não usar JSON re-serializado.
        $raw = $request->getContent();

        return ChatwootWebhookSignature::isValid($secret, $timestamp, $raw, $signature);
    }
}

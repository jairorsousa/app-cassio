<?php

namespace App\Http\Controllers;

use App\Support\ChatwootOptOutWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook Chatwoot (somente leitura de eventos). Não responde no WhatsApp e não altera conversas.
 */
class ChatwootWebhookController extends Controller
{
    public function __invoke(Request $request, ChatwootOptOutWebhook $handler): JsonResponse
    {
        $configured = (string) config('services.chatwoot.webhook_token', '');
        if ($configured === '') {
            return response()->json(['status' => 'disabled', 'reason' => 'webhook_token_unset'], 503);
        }

        $provided = (string) ($request->header('X-Chatwoot-Webhook-Token')
            ?: $request->header('X-Webhook-Token')
            ?: $request->query('token', ''));

        if ($provided === '' || ! hash_equals($configured, $provided)) {
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
}

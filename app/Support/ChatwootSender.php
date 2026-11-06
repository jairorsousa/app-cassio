<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Envia UMA mensagem a UM contato pelo Chatwoot.
 * Resultado: accepted (Chatwoot aceitou), rejected (Chatwoot recusou com 4xx), not_sent (nada foi enviado),
 * uncertain (sem certeza se saiu: 5xx, timeout, conexão caiu) — nunca reenviado automaticamente.
 * "accepted" não significa entregue/lido no WhatsApp.
 */
class ChatwootSender
{
    private function base(): string
    {
        return rtrim((string) config('services.chatwoot.base_url'), '/').'/api/v1/accounts/'.config('services.chatwoot.account_id');
    }

    private function http()
    {
        return Http::withHeaders(['api_access_token' => (string) config('services.chatwoot.api_access_token')])->timeout(20);
    }

    public function send(int $contactId, string $body, ?array $media): array
    {
        try {
            $conv = $this->http()->get($this->base()."/contacts/{$contactId}/conversations");
        } catch (\Throwable $e) {
            return ['outcome' => 'not_sent', 'error' => 'Falha ao consultar conversas: '.class_basename($e)];
        }
        if ($conv->failed()) {
            return ['outcome' => 'not_sent', 'error' => 'Falha ao consultar conversas (HTTP '.$conv->status().')'];
        }
        $openId = collect($conv->json('payload') ?? $conv->json() ?? [])
            ->filter(fn ($c) => is_array($c) && ($c['status'] ?? null) === 'open')
            ->sortByDesc(fn ($c) => $c['last_activity_at'] ?? $c['updated_at'] ?? $c['created_at'] ?? 0)
            ->pluck('id')->filter()->map(fn ($id) => (int) $id)->first();

        try {
            if ($openId) {
                return $this->result($this->postMessage($openId, $body, $media));
            }
            if (! $media) {
                return $this->result($this->http()->post($this->base().'/conversations', [
                    'inbox_id' => (int) config('services.chatwoot.inbox_id'),
                    'contact_id' => $contactId,
                    'status' => 'open',
                    'assignee_id' => (int) config('services.chatwoot.assignee_id'),
                    'message' => ['content' => $body],
                ]));
            }
            $created = $this->http()->post($this->base().'/conversations', [
                'source_id' => 'broadcast-'.$contactId.'-'.\Illuminate\Support\Str::uuid(),
                'inbox_id' => (int) config('services.chatwoot.inbox_id'),
                'contact_id' => $contactId,
                'status' => 'open',
                'assignee_id' => (int) config('services.chatwoot.assignee_id'),
            ]);
            $newId = (int) (data_get($created->json(), 'id') ?? data_get($created->json(), 'payload.id') ?? 0);
            if ($created->failed() || ! $newId) {
                return ['outcome' => 'not_sent', 'error' => 'Falha ao criar conversa (HTTP '.$created->status().')'];
            }

            return $this->result($this->postMessage($newId, $body, $media));
        } catch (ConnectionException $e) {
            return ['outcome' => 'uncertain', 'error' => 'Conexão interrompida durante o envio'];
        }
    }

    private function postMessage(int $conversationId, string $body, ?array $media): Response
    {
        $url = $this->base()."/conversations/{$conversationId}/messages";
        if (! $media) {
            return $this->http()->post($url, ['content' => $body, 'message_type' => 'outgoing', 'private' => false, 'content_type' => 'text']);
        }
        $file = fopen(Storage::disk('local')->path($media['path']), 'r');
        try {
            return $this->http()->attach('attachments[]', $file, $media['name'], ['Content-Type' => $media['mime']])
                ->post($url, ['content' => $body, 'message_type' => 'outgoing', 'private' => 'false']);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    private function result(Response $r): array
    {
        if ($r->successful()) {
            $id = data_get($r->json(), 'id') ?? data_get($r->json(), 'messages.0.id');

            return ['outcome' => 'accepted', 'message_id' => $id !== null ? (string) $id : null];
        }

        return $r->serverError()
            ? ['outcome' => 'uncertain', 'error' => 'Chatwoot respondeu HTTP '.$r->status().' (pode ter saído)']
            : ['outcome' => 'rejected', 'error' => mb_substr('Chatwoot recusou (HTTP '.$r->status().'): '.$r->body(), 0, 300)];
    }
}

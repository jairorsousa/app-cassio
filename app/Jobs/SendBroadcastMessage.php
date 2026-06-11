<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0; // Allow it to run for a long time since it has sleeps
    
    protected array $contactIds;
    protected string $message;
    protected ?array $attachment;

    /**
     * Create a new job instance.
     */
    public function __construct(array $contactIds, string $message, ?array $attachment = null)
    {
        $this->contactIds = $contactIds;
        $this->message = $message;
        $this->attachment = $attachment;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.chatwoot.base_url'), '/');
        $accountId = config('services.chatwoot.account_id');
        $token = (string) config('services.chatwoot.api_access_token');

        if ($this->hasAttachment() && ! is_file($this->attachmentAbsolutePath())) {
            Log::error('Anexo da transmissão não encontrado: ' . $this->attachmentAbsolutePath());

            return;
        }

        try {
            collect($this->contactIds)->each(function ($idContato) use ($baseUrl, $accountId, $token) {
                $conversationId = $this->findLatestOpenConversationId($baseUrl, $accountId, $token, (int) $idContato);

                if ($conversationId === false) {
                    sleep(rand(45, 90));

                    return;
                }

                [$response, $context] = $this->sendToContact($baseUrl, $accountId, $token, (int) $idContato, $conversationId);

                if ($response === null || $response->failed()) {
                    Log::error("Erro na API para o contato {$idContato} ({$context}): " . ($response?->body() ?? 'sem resposta'));
                } else {
                    Log::info("Mensagem enviada com sucesso para o contato {$idContato} ({$context})");
                }

                // Delay aleatório entre 45 e 90 segundos para evitar bloqueios por spam
                sleep(rand(45, 90));
            });
        } finally {
            $this->deleteStoredAttachment();
        }
    }

    private function sendToContact(string $baseUrl, mixed $accountId, string $token, int $contactId, ?int $conversationId): array
    {
        if ($conversationId) {
            return [
                $this->sendMessageToConversation($baseUrl, $accountId, $token, $conversationId),
                "conversa existente {$conversationId}",
            ];
        }

        if (! $this->hasAttachment()) {
            return [
                $this->createConversationWithMessage($baseUrl, $accountId, $token, $contactId),
                'nova conversa',
            ];
        }

        $createResponse = $this->createConversation($baseUrl, $accountId, $token, $contactId);
        $newConversationId = $this->extractConversationId($createResponse);

        if ($createResponse->failed() || ! $newConversationId) {
            return [$createResponse, 'nova conversa com mídia'];
        }

        return [
            $this->sendMessageToConversation($baseUrl, $accountId, $token, $newConversationId),
            "nova conversa {$newConversationId} com mídia",
        ];
    }

    private function findLatestOpenConversationId(string $baseUrl, mixed $accountId, string $token, int $contactId): int|false|null
    {
        $response = Http::withHeaders([
            'api_access_token' => $token,
        ])->get("{$baseUrl}/api/v1/accounts/{$accountId}/contacts/{$contactId}/conversations");

        if ($response->failed()) {
            Log::error("Erro ao buscar conversas abertas do contato {$contactId}: " . $response->body());

            return false;
        }

        $conversations = $response->json('payload') ?? $response->json() ?? [];

        return collect($conversations)
            ->filter(fn ($conversation) => is_array($conversation) && ($conversation['status'] ?? null) === 'open')
            ->sortByDesc(fn ($conversation) => $conversation['last_activity_at'] ?? $conversation['updated_at'] ?? $conversation['created_at'] ?? 0)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->first();
    }

    private function sendMessageToConversation(string $baseUrl, mixed $accountId, string $token, int $conversationId)
    {
        if ($this->hasAttachment()) {
            return $this->sendMultipartMessageToConversation($baseUrl, $accountId, $token, $conversationId);
        }

        return Http::withHeaders([
            'api_access_token' => $token,
            'Content-Type' => 'application/json',
        ])->post("{$baseUrl}/api/v1/accounts/{$accountId}/conversations/{$conversationId}/messages", [
            'content' => $this->message,
            'message_type' => 'outgoing',
            'private' => false,
            'content_type' => 'text',
        ]);
    }

    private function sendMultipartMessageToConversation(string $baseUrl, mixed $accountId, string $token, int $conversationId)
    {
        $file = fopen($this->attachmentAbsolutePath(), 'r');

        try {
            return Http::withHeaders([
                'api_access_token' => $token,
            ])
                ->attach('attachments[]', $file, $this->attachmentFileName(), $this->attachmentHeaders())
                ->post("{$baseUrl}/api/v1/accounts/{$accountId}/conversations/{$conversationId}/messages", [
                    'content' => $this->message,
                    'message_type' => 'outgoing',
                    'private' => 'false',
                ]);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    private function createConversation(string $baseUrl, mixed $accountId, string $token, int $contactId)
    {
        return Http::withHeaders([
            'api_access_token' => $token,
        ])->post("{$baseUrl}/api/v1/accounts/{$accountId}/conversations", [
            'source_id' => 'broadcast-' . $contactId . '-' . Str::uuid(),
            'inbox_id' => (int) config('services.chatwoot.inbox_id'),
            'contact_id' => $contactId,
            'status' => 'open',
            'assignee_id' => (int) config('services.chatwoot.assignee_id'),
        ]);
    }

    private function createConversationWithMessage(string $baseUrl, mixed $accountId, string $token, int $contactId)
    {
        return Http::withHeaders([
            'api_access_token' => $token,
        ])->post("{$baseUrl}/api/v1/accounts/{$accountId}/conversations", [
            'inbox_id' => (int) config('services.chatwoot.inbox_id'),
            'contact_id' => $contactId,
            'status' => 'open',
            'assignee_id' => (int) config('services.chatwoot.assignee_id'),
            'message' => [
                'content' => $this->message,
            ],
        ]);
    }

    private function extractConversationId($response): ?int
    {
        $data = $response->json();
        $id = data_get($data, 'id')
            ?? data_get($data, 'payload.id')
            ?? data_get($data, 'conversation.id')
            ?? data_get($data, 'data.id');

        return $id ? (int) $id : null;
    }

    private function hasAttachment(): bool
    {
        return is_array($this->attachment)
            && ! empty($this->attachment['path']);
    }

    private function attachmentAbsolutePath(): string
    {
        return Storage::disk('local')->path($this->attachment['path']);
    }

    private function attachmentFileName(): string
    {
        return $this->attachment['name'] ?? basename((string) $this->attachment['path']);
    }

    private function attachmentHeaders(): array
    {
        return ! empty($this->attachment['mime_type'])
            ? ['Content-Type' => $this->attachment['mime_type']]
            : [];
    }

    private function deleteStoredAttachment(): void
    {
        if ($this->hasAttachment()) {
            Storage::disk('local')->delete($this->attachment['path']);
        }
    }
}

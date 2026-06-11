<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0; // Allow it to run for a long time since it has sleeps
    
    protected array $contactIds;
    protected string $message;

    /**
     * Create a new job instance.
     */
    public function __construct(array $contactIds, string $message)
    {
        $this->contactIds = $contactIds;
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $baseUrl = rtrim((string) config('services.chatwoot.base_url'), '/');
        $accountId = config('services.chatwoot.account_id');
        $token = (string) config('services.chatwoot.api_access_token');

        collect($this->contactIds)->each(function ($idContato) use ($baseUrl, $accountId, $token) {
            $conversationId = $this->findLatestOpenConversationId($baseUrl, $accountId, $token, (int) $idContato);

            if ($conversationId === false) {
                sleep(rand(45, 90));

                return;
            }

            $response = $conversationId
                ? $this->sendMessageToConversation($baseUrl, $accountId, $token, $conversationId)
                : $this->createConversationWithMessage($baseUrl, $accountId, $token, (int) $idContato);

            $context = $conversationId
                ? "conversa existente {$conversationId}"
                : 'nova conversa';

            if ($response === null || $response->failed()) {
                Log::error("Erro na API para o contato {$idContato} ({$context}): " . ($response?->body() ?? 'sem resposta'));
            } else {
                Log::info("Mensagem enviada com sucesso para o contato {$idContato} ({$context})");
            }

            // Delay aleatório entre 45 e 90 segundos para evitar bloqueios por spam
            sleep(rand(45, 90));
        });
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
}

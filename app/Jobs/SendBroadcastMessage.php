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
        $url = "{$baseUrl}/api/v1/accounts/{$accountId}/conversations";
        $token = (string) config('services.chatwoot.api_access_token');

        collect($this->contactIds)->each(function ($idContato) use ($url, $token) {
            
            $response = Http::withHeaders([
                'api_access_token' => $token
            ])->post($url, [
                "inbox_id"    => (int) config('services.chatwoot.inbox_id'),
                "contact_id"  => $idContato,
                "status"      => "open",
                "assignee_id" => (int) config('services.chatwoot.assignee_id'),
                "message"     => [
                    "content" => $this->message
                ]
            ]);

            if ($response->failed()) {
                Log::error("Erro na API para o contato {$idContato}: " . $response->body());
            } else {
                Log::info("Mensagem enviada com sucesso para o contato {$idContato}");
            }

            // Delay aleatório entre 30 e 45 segundos para evitar bloqueios por spam
            sleep(rand(30, 45));
        });
    }
}

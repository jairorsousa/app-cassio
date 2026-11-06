<?php

namespace App\Jobs;

use App\Support\InvitationMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendUserInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $invitationId,
        public string $plainToken,
        public string $dispatchToken,
        public int $actorId,
    ) {}

    public function handle(): void
    {
        InvitationMailService::deliver($this->invitationId, $this->plainToken, $this->dispatchToken);
    }

    public function failed(?\Throwable $e): void
    {
        InvitationMailService::markFailed($this->invitationId, $this->dispatchToken, 'Falha ao enviar e-mail.');
    }

    /** Evita vazar o token em logs de falha do worker. */
    public function __debugInfo(): array
    {
        return [
            'invitationId' => $this->invitationId,
            'dispatchToken' => substr($this->dispatchToken, 0, 8).'…',
            'actorId' => $this->actorId,
        ];
    }
}

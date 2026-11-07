<?php

namespace App\Jobs;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Support\ChatwootSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Uma tarefa curta por destinatário. Nunca tenta de novo sozinha: no máximo uma chamada de envio por pessoa. */
class SendTransmissionRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public int $recipientId) {}

    public function handle(ChatwootSender $sender): void
    {
        // Reserva atômica: só quem muda pending→sending envia.
        $r = DB::transaction(function () {
            $row = TransmissionRecipient::whereKey($this->recipientId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            $row->update(['status' => 'sending', 'attempted_at' => now()]);

            return $row;
        });
        if (! $r) {
            return;
        }
        $batch = $r->batch;
        if ($batch->status === 'queued') {
            $batch->update(['status' => 'running']);
        }

        if (! $r->contact_id) {
            $res = ['outcome' => 'not_sent', 'error' => 'Contato sem ID no Chatwoot'];
        } else {
            try {
                $res = $sender->send((int) $r->contact_id, (string) $batch->body, $batch->media());
            } catch (\Throwable $e) {
                $res = ['outcome' => 'uncertain', 'error' => 'Erro inesperado: '.class_basename($e)];
            }
        }

        $status = match ($res['outcome']) {
            'accepted' => 'sent',
            'rejected', 'not_sent' => 'failed',
            default => 'unconfirmed',
        };
        $r->update(['status' => $status, 'error' => isset($res['error']) ? mb_substr($res['error'], 0, 500) : null, 'external_message_id' => $res['message_id'] ?? null, 'finished_at' => now()]);

        TransmissionBatch::finalizeIfDone($batch->id);
    }
}

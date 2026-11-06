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

    public function __construct(public int $recipientId, public ?string $token = null) {}

    public function handle(ChatwootSender $sender): void
    {
        $batchId = TransmissionRecipient::whereKey($this->recipientId)->value('batch_id');
        if (! $batchId) {
            return;
        }
        // Reserva atômica (lote → destinatário, mesma ordem de trava usada por pausar/cancelar).
        $r = DB::transaction(function () use ($batchId) {
            $batch = TransmissionBatch::whereKey($batchId)->lockForUpdate()->first();
            if (! $batch || ! in_array($batch->status, ['queued', 'running'], true)) {
                return null; // pausada ou cancelada: não envia; ao retomar, uma nova tarefa é agendada.
            }
            $row = TransmissionRecipient::whereKey($this->recipientId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->dispatch_token !== $this->token) {
                return null;
            }
            // Pediu para não receber depois do agendamento: não chama o Chatwoot.
            if (\App\Domains\Broadcasts\Models\TransmissionSuppression::isActive($row->phone_hash)) {
                $row->update(['status' => 'suppressed', 'error' => 'Na lista "Não receber"', 'finished_at' => now()]);

                return false;
            }
            $row->update(['status' => 'sending', 'attempted_at' => now()]);
            if ($batch->status === 'queued') {
                $batch->update(['status' => 'running']);
            }

            return $row;
        });
        if ($r === false) {
            TransmissionBatch::finalizeIfDone($batchId);

            return;
        }
        if (! $r) {
            return;
        }
        $batch = $r->batch;

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

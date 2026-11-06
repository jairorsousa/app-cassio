<?php

namespace App\Support;

use App\Domains\Broadcasts\Models\TransmissionSuppression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Processa um evento Chatwoot de mensagem recebida e, se for opt-out explícito,
 * bloqueia o telefone na lista "Não receber". Não envia respostas nem chama a API.
 */
class ChatwootOptOutWebhook
{
    public const RESULT_SUPPRESSED = 'suppressed';

    public const RESULT_ALREADY = 'already_suppressed';

    public const RESULT_IGNORED = 'ignored';

    public const RESULT_RATE_LIMITED = 'rate_limited';

    /**
     * @return array{status: string, reason?: string}
     */
    public function handle(array $payload): array
    {
        if ($this->looksLikeBulk($payload)) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'bulk_payload'];
        }

        $event = (string) ($payload['event'] ?? '');
        if ($event !== '' && $event !== 'message_created') {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'irrelevant_event'];
        }

        // Sem "event" explícito, ainda aceita o formato clássico de message_created do Chatwoot.
        if ($event === '' && ! array_key_exists('content', $payload) && ! array_key_exists('message_type', $payload)) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'irrelevant_event'];
        }

        if ($this->isPrivate($payload) || $this->isOutgoing($payload)) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'not_incoming_public'];
        }

        // Fail-closed: só a inbox configurada (CMS-6000 / conta 1). Sem ID no payload ou ID diferente = ignore.
        if (! $this->inboxAllowed($payload)) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'wrong_inbox'];
        }

        $content = $this->messageContent($payload);
        if (TransmissionSuppression::classify($content) !== 'optout') {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'not_optout'];
        }

        $phone = $this->extractPhone($payload);
        if ($phone === null) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'missing_phone'];
        }

        if (! $this->consumeOptOutSlot()) {
            Log::warning('chatwoot_optout_rate_limited');

            return ['status' => self::RESULT_RATE_LIMITED, 'reason' => 'rate_limited'];
        }

        $name = $this->extractName($payload);
        $existingHash = TransmissionAudience::hash($phone);
        $wasActive = TransmissionSuppression::isActive($existingHash);

        $row = TransmissionSuppression::suppress(
            $phone,
            $name,
            'Resposta SAIR no Chatwoot',
            null,
            'chatwoot'
        );

        if ($row === null) {
            return ['status' => self::RESULT_IGNORED, 'reason' => 'invalid_phone'];
        }

        if ($wasActive) {
            return ['status' => self::RESULT_ALREADY];
        }

        Log::info('chatwoot_optout_suppressed', [
            'phone_hash' => $row->phone_hash,
            'source' => 'chatwoot',
            'suppressed_at' => optional($row->suppressed_at)?->toIso8601String(),
        ]);

        return ['status' => self::RESULT_SUPPRESSED];
    }

    private function looksLikeBulk(array $payload): bool
    {
        foreach (['messages', 'payloads', 'items', 'contacts'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && count($payload[$key]) > 1) {
                return true;
            }
        }

        return false;
    }

    private function isPrivate(array $payload): bool
    {
        return filter_var($payload['private'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function isOutgoing(array $payload): bool
    {
        $type = $payload['message_type'] ?? null;
        if ($type === null || $type === '') {
            // Sem tipo explícito não bloqueamos ninguém (evita falso positivo).
            return true;
        }
        if (is_string($type) && ! ctype_digit($type)) {
            return strtolower(trim($type)) !== 'incoming';
        }

        // Chatwoot: 0 incoming, 1 outgoing, 2 activity, 3 template
        return (int) $type !== 0;
    }

    private function messageContent(array $payload): string
    {
        $content = $payload['content'] ?? data_get($payload, 'message.content') ?? '';

        return is_string($content) ? $content : '';
    }

    private function extractPhone(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'sender.phone_number'),
            data_get($payload, 'sender.phone'),
            data_get($payload, 'conversation.meta.sender.phone_number'),
            data_get($payload, 'conversation.meta.sender.phone'),
            data_get($payload, 'conversation.contact.phone_number'),
            data_get($payload, 'contact.phone_number'),
        ];

        foreach ($candidates as $raw) {
            $phone = TransmissionAudience::e164(is_string($raw) ? $raw : null);
            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    private function extractName(array $payload): ?string
    {
        foreach ([
            data_get($payload, 'sender.name'),
            data_get($payload, 'conversation.meta.sender.name'),
            data_get($payload, 'contact.name'),
        ] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }


    /** Fail-closed: exige CHATWOOT_OPT_OUT_INBOX_ID e igualdade com a inbox do evento. */
    private function inboxAllowed(array $payload): bool
    {
        $expected = (int) config('services.chatwoot.opt_out_inbox_id', 0);
        if ($expected <= 0) {
            return false;
        }
        $got = $this->extractInboxId($payload);

        return $got !== null && $got === $expected;
    }

    private function extractInboxId(array $payload): ?int
    {
        $candidates = [
            data_get($payload, 'inbox.id'),
            data_get($payload, 'inbox_id'),
            data_get($payload, 'conversation.inbox_id'),
            data_get($payload, 'conversation.inbox.id'),
        ];
        foreach ($candidates as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
                $id = (int) $raw;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** Limita opt-outs reais por minuto (defesa se o token vazar). */
    private function consumeOptOutSlot(): bool
    {
        $limit = max(1, (int) config('services.chatwoot.webhook_optout_per_minute', 30));
        $key = 'chatwoot_optout:'.now()->format('YmdHi');
        Cache::add($key, 0, 120);
        $count = (int) Cache::increment($key);

        return $count <= $limit;
    }
}

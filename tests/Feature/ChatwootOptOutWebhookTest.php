<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionSuppression;
use App\Support\ChatwootWebhookSignature;
use App\Support\TransmissionAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatwootOptOutWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-webhook-token-not-for-production';

    private string $secret = 'test-webhook-signing-secret-not-for-production';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.chatwoot.webhook_token' => $this->token,
            'services.chatwoot.webhook_secret' => '', // assinatura desligada por padrão nos testes de token
            'services.chatwoot.webhook_signature_tolerance' => 300,
            'services.chatwoot.webhook_optout_per_minute' => 30,
            'services.chatwoot.opt_out_inbox_id' => 15,
        ]);
        Cache::flush();
        Http::fake(); // nenhuma chamada externa
    }

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'event' => 'message_created',
            'id' => 1001,
            'content' => 'SAIR',
            'message_type' => 'incoming',
            'private' => false,
            'sender' => [
                'type' => 'contact',
                'name' => 'Fulano',
                'phone_number' => '+5585999900099',
            ],
            'inbox' => ['id' => 15],
            'conversation' => [
                'inbox_id' => 15,
                'meta' => [
                    'sender' => [
                        'name' => 'Fulano',
                        'phone_number' => '+5585999900099',
                    ],
                ],
            ],
        ], $over);
    }

    private function postWebhook(array $payload, ?string $token = null, array $headers = [])
    {
        $token ??= $this->token;
        $headers = array_merge(['X-Chatwoot-Webhook-Token' => $token], $headers);

        return $this->postJson('/webhooks/chatwoot', $payload, $headers);
    }

    /** POST com corpo raw + headers de assinatura Chatwoot (não re-serializa). */
    private function postSigned(array $payload, ?string $secret = null, ?int $timestamp = null, ?string $signatureOverride = null)
    {
        $secret ??= $this->secret;
        $timestamp ??= time();
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = $signatureOverride ?? ChatwootWebhookSignature::expected($secret, (string) $timestamp, $raw);

        return $this->call(
            'POST',
            '/webhooks/chatwoot',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CHATWOOT_SIGNATURE' => $sig,
                'HTTP_X_CHATWOOT_TIMESTAMP' => (string) $timestamp,
            ],
            $raw
        );
    }

    public function test_token_ausente_ou_invalido_e_recusado(): void
    {
        $this->postJson('/webhooks/chatwoot', $this->payload())->assertUnauthorized();
        $this->postWebhook($this->payload(), 'errado')->assertUnauthorized();
    }

    public function test_sem_token_nem_secret_fica_desligado(): void
    {
        config([
            'services.chatwoot.webhook_token' => '',
            'services.chatwoot.webhook_secret' => '',
        ]);
        $this->postWebhook($this->payload())->assertStatus(503)->assertJson(['status' => 'disabled']);
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_sair_incoming_bloqueia_telefone_idempotente(): void
    {
        $this->postWebhook($this->payload())->assertOk()->assertJson(['status' => 'suppressed']);
        $this->assertSame(1, TransmissionSuppression::count());
        $row = TransmissionSuppression::sole();
        $this->assertSame('chatwoot', $row->source);
        $this->assertTrue(TransmissionSuppression::isActive(TransmissionAudience::hash('+5585999900099')));
        $this->assertSame('Fulano', $row->name);

        $this->postWebhook($this->payload(['id' => 1002, 'content' => ' sair! ']))
            ->assertOk()
            ->assertJson(['status' => 'already_suppressed']);
        $this->assertSame(1, TransmissionSuppression::count());
        Http::assertNothingSent();
    }

    public function test_aceita_token_na_query_para_compatibilidade_local(): void
    {
        $this->postJson('/webhooks/chatwoot?token='.$this->token, $this->payload(['content' => 'parar']))
            ->assertOk()
            ->assertJson(['status' => 'suppressed']);
        $this->assertSame(1, TransmissionSuppression::count());
    }

    public function test_assinatura_valida_com_raw_body_autoriza_sem_token(): void
    {
        config([
            'services.chatwoot.webhook_token' => '',
            'services.chatwoot.webhook_secret' => $this->secret,
        ]);

        $this->postSigned($this->payload())
            ->assertOk()
            ->assertJson(['status' => 'suppressed']);
        $this->assertSame(1, TransmissionSuppression::count());
        Http::assertNothingSent();
    }

    public function test_assinatura_invalida_e_recusada(): void
    {
        config([
            'services.chatwoot.webhook_token' => '',
            'services.chatwoot.webhook_secret' => $this->secret,
        ]);

        $this->postSigned($this->payload(), signatureOverride: 'sha256='.str_repeat('0', 64))
            ->assertUnauthorized();
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_assinatura_com_corpo_alterado_falha(): void
    {
        config([
            'services.chatwoot.webhook_token' => '',
            'services.chatwoot.webhook_secret' => $this->secret,
        ]);

        $payload = $this->payload();
        $timestamp = time();
        $rawAssinado = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig = ChatwootWebhookSignature::expected($this->secret, (string) $timestamp, $rawAssinado);

        // Corpo diferente do que foi assinado.
        $payload['content'] = 'SAIR tampered';
        $rawTampered = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            '/webhooks/chatwoot',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CHATWOOT_SIGNATURE' => $sig,
                'HTTP_X_CHATWOOT_TIMESTAMP' => (string) $timestamp,
            ],
            $rawTampered
        )->assertUnauthorized();
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_secret_vazio_nao_aceita_assinatura_mesmo_com_headers(): void
    {
        config([
            'services.chatwoot.webhook_token' => $this->token,
            'services.chatwoot.webhook_secret' => '',
        ]);

        // Sem token e com assinatura "válida" para um secret fictício → 401 (modo assinatura desligado).
        $payload = $this->payload();
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = ChatwootWebhookSignature::expected('outro-secret', $ts, $raw);

        $this->call(
            'POST',
            '/webhooks/chatwoot',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CHATWOOT_SIGNATURE' => $sig,
                'HTTP_X_CHATWOOT_TIMESTAMP' => $ts,
            ],
            $raw
        )->assertUnauthorized();
    }

    public function test_timestamp_expirado_e_recusado(): void
    {
        config([
            'services.chatwoot.webhook_token' => '',
            'services.chatwoot.webhook_secret' => $this->secret,
            'services.chatwoot.webhook_signature_tolerance' => 300,
        ]);

        $this->postSigned($this->payload(), timestamp: time() - 301)
            ->assertUnauthorized();
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_outgoing_private_e_eventos_irrelevantes_nao_bloqueiam(): void
    {
        $this->postWebhook($this->payload(['message_type' => 'outgoing']))->assertOk()->assertJsonPath('status', 'ignored');
        $this->postWebhook($this->payload(['message_type' => 1]))->assertOk()->assertJsonPath('reason', 'not_incoming_public');
        $this->postWebhook($this->payload(['private' => true]))->assertOk()->assertJsonPath('reason', 'not_incoming_public');
        $this->postWebhook($this->payload(['event' => 'conversation_status_changed', 'content' => 'SAIR']))
            ->assertOk()->assertJsonPath('reason', 'irrelevant_event');
        $this->postWebhook($this->payload(['content' => 'quero saber mais sobre o caso']))
            ->assertOk()->assertJsonPath('reason', 'not_optout');
        $this->postWebhook($this->payload(['content' => 'vou sair do escritório']))
            ->assertOk()->assertJsonPath('reason', 'not_optout');
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_sem_telefone_valido_ignora(): void
    {
        $this->postWebhook($this->payload([
            'sender' => ['type' => 'contact', 'name' => 'X', 'phone_number' => '123'],
            'conversation' => ['meta' => ['sender' => ['phone_number' => '']]],
        ]))->assertOk()->assertJsonPath('reason', 'missing_phone');
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_payload_em_massa_e_recusado(): void
    {
        $this->postWebhook($this->payload(['messages' => [
            ['content' => 'SAIR', 'phone' => '+5585999900001'],
            ['content' => 'SAIR', 'phone' => '+5585999900002'],
        ]]))->assertOk()->assertJsonPath('reason', 'bulk_payload');
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_limite_por_minuto_impede_bloqueio_massivo(): void
    {
        config(['services.chatwoot.webhook_optout_per_minute' => 2]);
        $this->postWebhook($this->payload(['sender' => ['type' => 'contact', 'phone_number' => '+5585999900001', 'name' => 'A']]))
            ->assertOk()->assertJson(['status' => 'suppressed']);
        $this->postWebhook($this->payload(['id' => 2, 'sender' => ['type' => 'contact', 'phone_number' => '+5585999900002', 'name' => 'B']]))
            ->assertOk()->assertJson(['status' => 'suppressed']);
        $this->postWebhook($this->payload(['id' => 3, 'sender' => ['type' => 'contact', 'phone_number' => '+5585999900003', 'name' => 'C']]))
            ->assertStatus(429)->assertJson(['status' => 'rate_limited']);
        $this->assertSame(2, TransmissionSuppression::count());
    }

    public function test_message_type_numerico_incoming_funciona(): void
    {
        $this->postWebhook($this->payload(['message_type' => 0, 'content' => 'Não quero mais receber']))
            ->assertOk()
            ->assertJson(['status' => 'suppressed']);
        $this->assertSame('chatwoot', TransmissionSuppression::sole()->source);
    }

    public function test_inbox_fora_da_permitida_e_fail_closed(): void
    {
        $this->postWebhook($this->payload([
            'inbox' => ['id' => 99],
            'conversation' => ['inbox_id' => 99],
        ]))->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'wrong_inbox']);
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_sem_inbox_no_payload_e_fail_closed(): void
    {
        $payload = $this->payload();
        unset($payload['inbox'], $payload['conversation']['inbox_id']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'wrong_inbox']);
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_opt_out_inbox_id_zero_nao_bloqueia_ninguem(): void
    {
        config(['services.chatwoot.opt_out_inbox_id' => 0]);
        $this->postWebhook($this->payload())->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'wrong_inbox']);
        $this->assertSame(0, TransmissionSuppression::count());
    }
}

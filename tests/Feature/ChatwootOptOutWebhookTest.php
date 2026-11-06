<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionSuppression;
use App\Support\TransmissionAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatwootOptOutWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-webhook-token-not-for-production';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.chatwoot.webhook_token' => $this->token,
            'services.chatwoot.webhook_optout_per_minute' => 30,
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
            'conversation' => [
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

    public function test_token_ausente_ou_invalido_e_recusado(): void
    {
        $this->postJson('/webhooks/chatwoot', $this->payload())->assertUnauthorized();
        $this->postWebhook($this->payload(), 'errado')->assertUnauthorized();
    }

    public function test_sem_token_configurado_fica_desligado(): void
    {
        config(['services.chatwoot.webhook_token' => '']);
        $this->postWebhook($this->payload())->assertStatus(503)->assertJson(['status' => 'disabled']);
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_sair_incoming_bloqueia_telefone_idempotente(): void
    {
        $r1 = $this->postWebhook($this->payload())->assertOk()->assertJson(['status' => 'suppressed']);
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

    public function test_aceita_token_na_query_para_url_do_chatwoot(): void
    {
        $this->postJson('/webhooks/chatwoot?token='.$this->token, $this->payload(['content' => 'parar']))
            ->assertOk()
            ->assertJson(['status' => 'suppressed']);
        $this->assertSame(1, TransmissionSuppression::count());
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
}

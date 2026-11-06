<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Jobs\SendTransmissionRecipient;
use App\Models\User;
use App\Support\ChatwootSender;
use App\Support\TransmissionAudience;
use App\Support\TransmissionDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransmissionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function audience(int $n, array $labels = ['clientes']): array
    {
        $by = [];
        foreach ($labels as $l) {
            $by[$l] = collect(range(1, $n))->map(fn ($i) => ['id' => $i, 'name' => "Pessoa $i", 'phone_number' => '+55859999'.str_pad($i, 5, '0', STR_PAD_LEFT)])->all();
        }

        return TransmissionAudience::build($by);
    }

    /** Chatwoot simulado: contatos em $fail recebem 422 (Falhou), em $uncertain 502 (Sem confirmação). */
    private function fakeChatwoot(array $fail = [], array $uncertain = []): void
    {
        Http::fake(function (Request $req) use ($fail, $uncertain) {
            if (preg_match('#/contacts/(\d+)/conversations#', $req->url(), $m)) {
                return Http::response(['payload' => [['id' => (int) $m[1], 'status' => 'open']]]);
            }
            if (preg_match('#/conversations/(\d+)/messages#', $req->url(), $m)) {
                $id = (int) $m[1];
                if (in_array($id, $fail, true)) {
                    return Http::response(['error' => 'x'], 422);
                }
                if (in_array($id, $uncertain, true)) {
                    return Http::response('erro', 502);
                }

                return Http::response(['id' => 1000 + $id]);
            }

            return Http::response([], 404);
        });
    }

    private function runJob(int $recipientId): void
    {
        (new SendTransmissionRecipient($recipientId, TransmissionRecipient::whereKey($recipientId)->value('dispatch_token')))->handle(app(ChatwootSender::class));
    }

    private function runAll(TransmissionBatch $b): void
    {
        foreach ($b->recipients()->orderBy('position')->pluck('id') as $id) {
            $this->runJob($id);
        }
    }

    private function messagesSent(): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), '/messages'))->count();
    }

    public function test_historico_mostra_autor_texto_etiquetas_contagens_e_so_lotes_do_dono(): void
    {
        Queue::fake();
        $this->fakeChatwoot(fail: [2], uncertain: [3]);
        $cassio = User::factory()->create(['name' => 'Cassio Mota']);
        $other = User::factory()->create(['name' => 'Outra Pessoa']);
        $b = TransmissionDispatcher::start($cassio->id, 'Bom dia a todos', null, $this->audience(3, ['clientes', 'parceiros']), ['clientes', 'parceiros']);
        TransmissionDispatcher::start($other->id, 'Mensagem secreta de outro', null, $this->audience(1), ['x']);
        $this->runAll($b);
        $this->assertSame(['clientes', 'parceiros'], $b->fresh()->labels);
        $this->assertSame(3, $b->fresh()->recipients_count);

        $this->actingAs($cassio);
        $c = Volt::test('broadcasts.history')
            ->assertSee('Transmissão #'.$b->id)->assertSee('Cassio Mota')->assertSee('Bom dia a todos')
            ->assertSee('Etiquetas: clientes, parceiros')->assertSee('Aceita pelo Chatwoot: 1')->assertSee('Falhou (não enviada): 1')->assertSee('Sem confirmação: 1')
            ->assertSee('Aqui começa o histórico')->assertDontSee('Mensagem secreta de outro');
        $c->call('openBatch', $b->id)->assertSee('Pessoa 1')->assertSee('••••0001')->assertDontSee('+5585999900001')
            ->set('recipientStatus', 'failed')->assertSee('Pessoa 2')->assertDontSee('Pessoa 1')
            ->set('recipientStatus', '')->set('recipientSearch', 'Pessoa 3')->assertSee('Pessoa 3')->assertDontSee('Pessoa 2');
    }

    public function test_historico_pagina_lotes_de_10_e_destinatarios_de_20(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $first = TransmissionDispatcher::start($u->id, 'Lote grande', null, $this->audience(25), ['x']);
        foreach (range(1, 11) as $i) {
            TransmissionDispatcher::start($u->id, "Lote $i", null, $this->audience(1), ['x']);
        }
        $this->actingAs($u);
        Volt::test('broadcasts.history')->assertSee('Página 1 de 2')->assertDontSee('Lote grande')
            ->call('batchPageTo', 2)->assertSee('Lote grande')
            ->call('openBatch', $first->id)->assertSee('Página 1 de 2 · 25 destinatário(s)');
    }

    public function test_outro_usuario_nao_controla_lote_alheio(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $b = TransmissionDispatcher::start($owner->id, 'Oi', null, $this->audience(2), ['x']);
        $this->actingAs($intruder);
        foreach (['pause', 'cancel', 'resume', 'retryFailed', 'openBatch'] as $m) {
            try {
                Volt::test('broadcasts.history')->call($m, $b->id);
                $this->fail("$m deveria bloquear");
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('queued', $b->fresh()->status);
    }

    public function test_pausar_impede_envio_e_retomar_reagenda_so_pendentes_com_intervalo(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $u = User::factory()->create();
        $b = TransmissionDispatcher::start($u->id, 'Oi', null, $this->audience(5), ['x']);
        $ids = $b->recipients()->orderBy('position')->pluck('id')->all();
        $this->runJob($ids[0]);
        $oldToken = TransmissionRecipient::whereKey($ids[1])->value('dispatch_token');

        $this->actingAs($u);
        Volt::test('broadcasts.history')->call('pause', $b->id)->assertSee('Pausada');
        foreach (array_slice($ids, 1) as $id) {
            $this->runJob($id);
        }
        $this->assertSame(1, $this->messagesSent(), 'Pausada: nenhum novo envio.');
        $this->assertSame(4, $b->recipients()->where('status', 'pending')->count());

        Queue::fake();
        $this->travel(10)->minutes();
        Volt::test('broadcasts.history')->call('resume', $b->id);
        Queue::assertPushed(SendTransmissionRecipient::class, 4);
        $times = $b->recipients()->where('status', 'pending')->orderBy('position')->pluck('scheduled_at')->map->getTimestamp()->all();
        for ($i = 1; $i < count($times); $i++) {
            $this->assertGreaterThanOrEqual(45, $times[$i] - $times[$i - 1]);
            $this->assertLessThanOrEqual(90, $times[$i] - $times[$i - 1]);
        }
        // Tarefa antiga (de antes da pausa) não envia mais.
        (new SendTransmissionRecipient($ids[1], $oldToken))->handle(app(ChatwootSender::class));
        $this->assertSame(1, $this->messagesSent());
        $this->runAll($b);
        $this->assertSame(5, $this->messagesSent());
        $this->assertSame('done', $b->fresh()->status);
    }

    public function test_cancelar_para_os_pendentes_e_mantem_aceitas(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $u = User::factory()->create();
        $b = TransmissionDispatcher::start($u->id, 'Oi', null, $this->audience(4), ['x']);
        $ids = $b->recipients()->orderBy('position')->pluck('id')->all();
        $this->runJob($ids[0]);
        $this->actingAs($u);
        Volt::test('broadcasts.history')->assertSeeHtml('Cancelar transmissão? Mensagens já aceitas não podem ser retiradas.')
            ->call('cancel', $b->id)->assertSee('Cancelada');
        $this->runAll($b);
        $this->assertSame(1, $this->messagesSent());
        $this->assertSame(['sent' => 1, 'cancelled' => 3], array_filter($b->fresh()->counts()));
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertNotNull($b->fresh()->finished_at);
        $this->assertFalse(TransmissionDispatcher::cancel($b->id, $u->id), 'Não cancela de novo.');
    }

    public function test_reenviar_somente_falhas_cria_novo_lote_e_nao_mexe_no_original(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeChatwoot(fail: [2, 4], uncertain: [3]);
        $u = User::factory()->create();
        $img = UploadedFile::fake()->image('foto.png');
        $b = TransmissionDispatcher::start($u->id, 'Promo', $img, $this->audience(4, ['clientes', 'parceiros']), ['clientes', 'parceiros']);
        $this->runAll($b);
        $before = $b->fresh()->counts();
        $this->assertSame('done_with_errors', $b->fresh()->status);
        Storage::disk('local')->assertExists($b->media_path); // guardado porque há falhas

        $this->actingAs($u);
        Volt::test('broadcasts.history')->call('openBatch', $b->id)->assertSee('Reenviar somente falhas (2)')
            ->assertSeeHtml('NÃO serão reenviadas')->call('retryFailed', $b->id)->assertSee('Reenvio das falhas da #'.$b->id);

        $new = TransmissionBatch::where('retry_of_id', $b->id)->sole();
        $this->assertSame([2, 4], $new->recipients()->orderBy('contact_id')->pluck('contact_id')->all());
        $this->assertSame('Promo', $new->body);
        $this->assertSame(['clientes', 'parceiros'], $new->labels);
        $this->assertSame($u->id, $new->created_by);
        Storage::disk('local')->assertExists($new->media_path);
        $this->assertSame('foto.png', $new->media_name);
        $this->assertSame($before, $b->fresh()->counts(), 'Original intacto.');
        $this->assertSame('done_with_errors', $b->fresh()->status);

        // Não reenvia duas vezes o mesmo lote.
        $this->expectException(\RuntimeException::class);
        TransmissionDispatcher::retryFailed($b->id, $u->id);
    }

    public function test_reenvio_bloqueado_sem_anexo_original_e_sem_confirmacao_nunca_reenvia(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeChatwoot(fail: [1], uncertain: [2]);
        $u = User::factory()->create();
        $b = TransmissionDispatcher::start($u->id, 'Promo', UploadedFile::fake()->image('foto.png'), $this->audience(2), ['x']);
        $this->runAll($b);
        Storage::disk('local')->deleteDirectory('transmissions/'.$b->id);

        $this->actingAs($u);
        Volt::test('broadcasts.history')->call('openBatch', $b->id)->assertSee('o anexo original foi removido')
            ->call('retryFailed', $b->id)->assertSee('o anexo original foi removido');
        $this->assertSame(0, TransmissionBatch::where('retry_of_id', $b->id)->count());

    }

    public function test_sem_confirmacao_nunca_entra_no_reenvio(): void
    {
        Queue::fake();
        $this->fakeChatwoot(uncertain: [1]);
        $u = User::factory()->create();
        $c = TransmissionDispatcher::start($u->id, 'Oi', null, $this->audience(1), ['x']);
        $this->runAll($c);
        $this->assertSame('Não há destinatários com falha.', TransmissionDispatcher::retryInfo($c->fresh())['blocked']);
    }
}

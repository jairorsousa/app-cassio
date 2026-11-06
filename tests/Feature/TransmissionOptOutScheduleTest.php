<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Domains\Broadcasts\Models\TransmissionSchedule;
use App\Domains\Broadcasts\Models\TransmissionSuppression;
use App\Jobs\SendTransmissionRecipient;
use App\Models\User;
use App\Support\BroadcastComposer;
use App\Support\ChatwootSender;
use App\Support\TransmissionAudience;
use App\Support\TransmissionDispatcher;
use App\Support\TransmissionScheduler;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransmissionOptOutScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const FOOTER = 'Se não quiser mais receber estas mensagens, responda SAIR.';

    /** Chatwoot simulado: etiquetas, contatos por etiqueta (todos com 3 pessoas) e envio aceito. */
    private function fakeChatwoot(int $people = 3, bool $contactsFail = false): void
    {
        Http::fake(function (Request $req) use ($people, $contactsFail) {
            $url = $req->url();
            if (str_ends_with(parse_url($url, PHP_URL_PATH), '/labels')) {
                return Http::response(['payload' => [['title' => 'clientes'], ['title' => 'parceiros']]]);
            }
            if (str_contains($url, '/contacts/filter')) {
                if ($contactsFail) {
                    return Http::response('erro', 500);
                }

                return str_contains($url, 'page=1')
                    ? Http::response(['payload' => collect(range(1, $people))->map(fn ($i) => ['id' => $i, 'name' => "Pessoa $i", 'phone_number' => '+55859999'.str_pad($i, 5, '0', STR_PAD_LEFT)])->all()])
                    : Http::response(['payload' => []]);
            }
            if (preg_match('#/contacts/(\d+)/conversations#', $url, $m)) {
                return Http::response(['payload' => [['id' => (int) $m[1], 'status' => 'open']]]);
            }
            if (str_contains($url, '/messages')) {
                return Http::response(['id' => 1]);
            }

            return Http::response([], 404);
        });
    }

    private function phone(int $i): string
    {
        return '+55859999'.str_pad($i, 5, '0', STR_PAD_LEFT);
    }

    private function messagesSent(): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), '/messages'))->count();
    }

    private function runJob(int $id): void
    {
        (new SendTransmissionRecipient($id, TransmissionRecipient::whereKey($id)->value('dispatch_token')))->handle(app(ChatwootSender::class));
    }

    private function page()
    {
        $this->actingAs(User::factory()->create());

        return Volt::test('broadcasts.index')->call('toggleLabel', 'clientes')->set('message', "Olá\nTudo bem?");
    }

    public function test_rodape_de_saida_sai_uma_vez_e_igual_a_previa(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $expected = "Olá\n\nTudo bem?\n\n".self::FOOTER;
        $this->assertSame($expected, BroadcastComposer::compose("Olá\nTudo bem?", null, true, true));
        $this->assertSame($expected, BroadcastComposer::compose($expected, null, true, true), 'Não duplica.');
        $c = $this->page()->assertSet('includeOptOut', true)->assertSee(self::FOOTER)->call('review')->call('confirmSend');
        $this->assertSame($expected, TransmissionBatch::sole()->body);
        $this->page()->set('includeOptOut', false)->call('review')->call('confirmSend');
        $this->assertSame("Olá\n\nTudo bem?", TransmissionBatch::latest('id')->first()->body);
    }

    public function test_lista_nao_receber_tira_da_revisao_e_do_lote(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        TransmissionSuppression::suppress('85 99990-0002', 'Pessoa 2', 'respondeu SAIR', null);
        $c = $this->page()->call('review')->assertSeeHtml('data-review-suppressed>1<')->assertSeeHtml('data-review-total>2<');
        $c->call('confirmSend');
        $b = TransmissionBatch::sole();
        $this->assertSame(2, $b->recipients_count);
        $this->assertSame(1, $b->suppressed_omitted);
        $this->assertSame(0, $b->recipients()->where('phone_hash', TransmissionAudience::hash($this->phone(2)))->count());
    }

    public function test_saida_depois_de_agendado_bloqueia_no_job_sem_chamar_chatwoot(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $b = TransmissionDispatcher::start(null, 'Oi', null, TransmissionAudience::build(['x' => [['id' => 1, 'name' => 'A', 'phone_number' => $this->phone(1)]]]), ['x']);
        TransmissionSuppression::suppress($this->phone(1), null, null, null);
        $this->runJob($b->recipients()->value('id'));
        $this->assertSame(0, Http::recorded()->count(), 'Nenhuma chamada externa.');
        $this->assertSame('suppressed', $b->recipients()->value('status'));
        $this->assertSame('done', $b->fresh()->status);
    }

    public function test_voltar_a_receber_so_por_acao_explicita(): void
    {
        $this->actingAs(User::factory()->create());
        $c = Volt::test('broadcasts.suppressions')->set('phone', '(85) 99990-0007')->set('name', 'Maria')->call('add')->assertHasNoErrors()
            ->assertSee('Maria')->assertSee('••••0007')->assertDontSee('99990-0007');
        Volt::test('broadcasts.suppressions')->set('phone', '123')->call('add')->assertHasErrors('phone');
        $row = TransmissionSuppression::sole();
        $this->assertTrue(TransmissionSuppression::isActive(TransmissionAudience::hash($this->phone(7))));
        TransmissionSuppression::suppress($this->phone(7), 'Maria', null, null);
        $this->assertSame(1, TransmissionSuppression::count(), 'Mesmo telefone uma vez só.');
        $c->call('restore', $row->id)->assertSee('voltou a receber');
        $this->assertFalse(TransmissionSuppression::isActive(TransmissionAudience::hash($this->phone(7))));
        $this->assertNotNull($row->fresh()->restored_at);
    }

    public function test_classificador_de_resposta_nao_esta_ligado_a_nada(): void
    {
        $this->assertSame('optout', TransmissionSuppression::classify(' SAIR '));
        $this->assertSame('optout', TransmissionSuppression::classify('Não quero mais receber!'));
        $this->assertSame('unknown', TransmissionSuppression::classify('quero saber mais'));
        $this->assertSame(0, TransmissionSuppression::count());
    }

    public function test_agendamento_valida_data_horario_e_antecedencia(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-09 10:00', 'America/Fortaleza')->utc());
        [$ok, $e] = TransmissionScheduler::firstRun('once', '2026-11-09', '10:30', 'morning');
        $this->assertNull($e);
        $this->assertSame('2026-11-09 13:30', $ok->utc()->format('Y-m-d H:i'), 'Instante correto (UTC).');
        $this->assertNotNull(TransmissionScheduler::firstRun('once', '2026-11-09', '10:02', 'morning')[1], 'Menos de 5 min.');
        $this->assertNotNull(TransmissionScheduler::firstRun('once', '2026-11-08', '10:30', 'morning')[1], 'Passado.');
        $this->assertNotNull(TransmissionScheduler::firstRun('once', '2026-11-10', '21:00', 'morning')[1], 'Noite.');
        $this->assertNotNull(TransmissionScheduler::firstRun('once', '2026-11-10', '07:59', 'morning')[1], 'Madrugada.');
        $this->assertNotNull(TransmissionScheduler::firstRun('once', '2026-02-30', '10:00', 'morning')[1], 'Data inexistente.');
        $this->assertNotNull(TransmissionScheduler::firstRun('weekly', '2026-11-12', '', 'morning')[1], 'Quinta não pode.');
        $this->assertNull(TransmissionScheduler::firstRun('weekly', '2026-11-10', '', 'afternoon')[1]);
        $this->assertNotNull(TransmissionScheduler::firstRun('weekly', '2026-11-09', '', 'morning')[1], 'Segunda 09:00 já passou.');
    }

    public function test_rotacao_semanal_seg_ter_qua_e_manha_tarde(): void
    {
        $first = CarbonImmutable::parse('2026-11-09 09:00', 'America/Fortaleza')->utc();
        $got = [];
        foreach (range(0, 4) as $k) {
            $got[] = TransmissionScheduler::weeklyOccurrence($first, 'morning', $k)->setTimezone('America/Fortaleza')->format('D d/m H:i');
        }
        $this->assertSame(['Mon 09/11 09:00', 'Tue 17/11 14:00', 'Wed 25/11 09:00', 'Mon 30/11 14:00', 'Tue 08/12 09:00'], $got);
        $keys = array_map(fn ($k) => TransmissionScheduler::weekKey(TransmissionScheduler::weeklyOccurrence($first, 'morning', $k)), range(0, 4));
        $this->assertSame($keys, array_unique($keys), 'Uma por semana.');
    }

    public function test_agendado_so_vira_lote_no_horario_e_comando_e_idempotente(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'America/Fortaleza')->utc());
        $c = $this->page()->set('sendMode', 'weekly')->set('scheduledDate', '2026-11-09')->set('weeklyPeriod', 'morning')
            ->call('review')->assertSeeHtml('data-review-schedule')->assertSee('09/11/2026 09:00');
        $this->assertSame(0, TransmissionSchedule::count(), 'Revisar não agenda.');
        $c->call('confirmSend');
        $s = TransmissionSchedule::sole();
        $this->assertSame('active', $s->status);
        $this->assertSame(0, TransmissionBatch::count());
        Queue::assertNothingPushed();

        $this->artisan('transmissions:dispatch-scheduled')->assertSuccessful();
        $this->assertSame(0, TransmissionBatch::count(), 'Antes do horário: nada.');

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-09 09:00:30', 'America/Fortaleza')->utc());
        TransmissionSuppression::suppress($this->phone(3), null, null, null);
        $this->artisan('transmissions:dispatch-scheduled')->assertSuccessful();
        $this->artisan('transmissions:dispatch-scheduled')->assertSuccessful();
        $b = TransmissionBatch::sole();
        $this->assertSame($s->id, $b->schedule_id);
        $this->assertSame(2, $b->recipients_count, 'Contatos relidos e lista "Não receber" aplicada.');
        $this->assertSame(1, $b->suppressed_omitted);
        $this->assertStringEndsWith(self::FOOTER, $b->body);
        $this->assertSame('2026-11-17 14:00', $s->fresh()->next_run_at->setTimezone('America/Fortaleza')->format('Y-m-d H:i'));
        $this->assertSame(0, $this->messagesSent(), 'Envios só pelas tarefas da fila (simulada).');
    }

    public function test_semanal_pausado_ou_cancelado_nao_gera_e_retomar_nao_dispara_atrasado(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $u = User::factory()->create();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'America/Fortaleza')->utc());
        [$first] = TransmissionScheduler::firstRun('weekly', '2026-11-09', '', 'morning');
        $s = TransmissionScheduler::create($u->id, 'weekly', 'Oi', ['clientes'], null, $first, 'morning');
        $this->assertTrue(TransmissionScheduler::pause($s->id, $u->id));
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-20 10:00', 'America/Fortaleza')->utc());
        $this->artisan('transmissions:dispatch-scheduled');
        $this->assertSame(0, TransmissionBatch::count());
        $this->assertTrue(TransmissionScheduler::resume($s->id, $u->id));
        $this->assertTrue($s->fresh()->next_run_at->isFuture());
        $this->artisan('transmissions:dispatch-scheduled');
        $this->assertSame(0, TransmissionBatch::count(), 'Sem envio atrasado.');
        $this->assertTrue(TransmissionScheduler::cancel($s->id, $u->id));
        Carbon::setTestNow(CarbonImmutable::parse('2027-01-20 10:00', 'America/Fortaleza')->utc());
        $this->artisan('transmissions:dispatch-scheduled');
        $this->assertSame(0, TransmissionBatch::count());
    }

    public function test_data_unica_falha_de_contatos_nao_gera_e_fica_registrada(): void
    {
        Queue::fake();
        $this->fakeChatwoot(contactsFail: true);
        $u = User::factory()->create();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'America/Fortaleza')->utc());
        [$first] = TransmissionScheduler::firstRun('once', '2026-11-06', '15:00', 'morning');
        $s = TransmissionScheduler::create($u->id, 'once', 'Oi', ['clientes'], null, $first, 'morning');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 15:01', 'America/Fortaleza')->utc());
        $this->artisan('transmissions:dispatch-scheduled');
        $this->assertSame(0, TransmissionBatch::count());
        $this->assertSame('failed', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->last_error);
        $this->artisan('transmissions:dispatch-scheduled');
        $this->assertSame(0, TransmissionBatch::count(), 'Não tenta de novo sozinho.');
    }

    public function test_modo_agora_continua_imediato_e_sem_etiqueta_nao_agenda(): void
    {
        Queue::fake();
        $this->fakeChatwoot();
        $this->page()->assertSet('sendMode', 'now')->call('review')->call('confirmSend');
        $this->assertSame(1, TransmissionBatch::count());
        $this->assertSame(0, TransmissionSchedule::count());
        Queue::assertPushed(SendTransmissionRecipient::class, 3);

        $this->actingAs(User::factory()->create());
        Volt::test('broadcasts.index')->set('message', 'Oi')->set('sendMode', 'once')->set('scheduledDate', now('America/Fortaleza')->addDay()->format('Y-m-d'))->set('scheduledTime', '10:00')
            ->call('review')->assertHasErrors('message')->call('confirmSend');
        $this->assertSame(0, TransmissionSchedule::count());
    }

    public function test_agendamentos_sao_privados_do_dono(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'America/Fortaleza')->utc());
        [$first] = TransmissionScheduler::firstRun('weekly', '2026-11-10', '', 'afternoon');
        $s = TransmissionScheduler::create($owner->id, 'weekly', 'Texto do dono', ['clientes'], null, $first, 'afternoon');
        $this->actingAs($other);
        Volt::test('broadcasts.history')->assertDontSee('Texto do dono');
        foreach (['pauseSchedule', 'cancelSchedule', 'resumeSchedule'] as $m) {
            try {
                Volt::test('broadcasts.history')->call($m, $s->id);
                $this->fail($m);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->actingAs($owner);
        Volt::test('broadcasts.history')->assertSee('Texto do dono')->assertSee('10/11/2026 14:00')->call('cancelSchedule', $s->id)->assertSee('Agendamento cancelado');
        $this->assertSame('cancelled', $s->fresh()->status);
    }
}

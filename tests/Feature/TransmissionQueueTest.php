<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Jobs\SendTransmissionRecipient;
use App\Models\User;
use App\Support\TransmissionAudience;
use App\Support\TransmissionDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransmissionQueueTest extends TestCase
{
    use RefreshDatabase;

    private function audience(int $n): array
    {
        return TransmissionAudience::build(['x' => collect(range(1, $n))->map(fn ($i) => ['id' => $i, 'name' => "P$i", 'phone_number' => '+55859999'.str_pad($i, 5, '0', STR_PAD_LEFT)])->all()]);
    }

    private function wav(): string
    {
        $data = str_repeat("\0\0", 800);
        $p = tempnam(sys_get_temp_dir(), 'au').'.wav';
        file_put_contents($p, 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data);

        return $p;
    }

    public function test_dedup_rules(): void
    {
        $a = TransmissionAudience::build([
            'clientes' => [['id' => 1, 'name' => 'A', 'phone_number' => '85999990001'], ['id' => 2, 'name' => 'A2', 'phone_number' => '+55 85 99999-0001']],
            'parceiros' => [['id' => 1, 'name' => 'A', 'phone_number' => '+5585999990001'], ['id' => 3, 'name' => 'Sem', 'phone_number' => null]],
        ]);
        $this->assertSame(1, $a['unique']);
        $this->assertSame(2, $a['duplicates']);
        $this->assertSame(1, $a['missing_phone']);
        $this->assertSame(['clientes', 'parceiros'], $a['recipients'][0]['labels']);
    }

    public function test_delays_are_45_to_90_seconds_apart_and_one_job_per_person(): void
    {
        Queue::fake();
        $b = TransmissionDispatcher::start(null, 'Oi', null, $this->audience(12), ['x']);
        $times = $b->recipients()->orderBy('position')->pluck('scheduled_at')->map->getTimestamp()->all();
        for ($i = 1; $i < count($times); $i++) {
            $gap = $times[$i] - $times[$i - 1];
            $this->assertGreaterThanOrEqual(45, $gap);
            $this->assertLessThanOrEqual(90, $gap);
        }
        Queue::assertPushed(SendTransmissionRecipient::class, 12);
        $this->assertSame(12, TransmissionRecipient::distinct('phone_hash')->count('phone_hash'));
        $this->assertNotSame('+5585999900001', TransmissionRecipient::where('phone_hash', TransmissionAudience::hash('+5585999900001'))->value('phone_hash'));
    }

    public function test_running_job_twice_calls_chatwoot_once(): void
    {
        Queue::fake();
        Http::fake(['*/contacts/*/conversations' => Http::response(['payload' => [['id' => 77, 'status' => 'open']]]), '*/conversations/77/messages' => Http::response(['id' => 991])]);
        $b = TransmissionDispatcher::start(null, 'Oi', null, $this->audience(1), ['x']);
        $id = $b->recipients()->value('id');
        (new SendTransmissionRecipient($id))->handle(app(\App\Support\ChatwootSender::class));
        (new SendTransmissionRecipient($id))->handle(app(\App\Support\ChatwootSender::class));
        Http::assertSentCount(2); // 1 consulta + 1 envio
        $r = TransmissionRecipient::find($id);
        $this->assertSame('sent', $r->status);
        $this->assertSame('991', $r->external_message_id);
        $this->assertSame('done', $b->fresh()->status);
    }

    public function test_server_error_is_unconfirmed_and_4xx_is_failed_without_retry(): void
    {
        Queue::fake();
        Http::fake(['*/contacts/1/conversations' => Http::response(['payload' => [['id' => 1, 'status' => 'open']]]), '*/contacts/2/conversations' => Http::response(['payload' => [['id' => 2, 'status' => 'open']]]),
            '*/conversations/1/messages' => Http::response('erro', 502), '*/conversations/2/messages' => Http::response(['error' => 'x'], 422)]);
        $b = TransmissionDispatcher::start(null, 'Oi', null, $this->audience(2), ['x']);
        foreach ($b->recipients as $r) {
            (new SendTransmissionRecipient($r->id))->handle(app(\App\Support\ChatwootSender::class));
        }
        $st = TransmissionRecipient::orderBy('contact_id')->pluck('status')->all();
        $this->assertSame(['unconfirmed', 'failed'], $st);
        $this->assertSame('done_with_errors', $b->fresh()->status);
        $this->assertSame(1, (new SendTransmissionRecipient(1))->tries);
    }

    public function test_audio_accepted_kept_until_last_and_exe_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        Http::fake(['*/contacts/*/conversations' => Http::response(['payload' => [['id' => 5, 'status' => 'open']]]), '*/conversations/5/messages' => Http::response(['id' => 1])]);
        $audio = UploadedFile::fake()->createWithContent('recado.wav', file_get_contents($this->wav()));
        $b = TransmissionDispatcher::start(null, 'Escute', $audio, $this->audience(2), ['x']);
        $this->assertSame('audio/wav', $b->media_mime);
        Storage::disk('local')->assertExists($b->media_path);
        [$r1, $r2] = $b->recipients()->orderBy('position')->get()->all();
        (new SendTransmissionRecipient($r1->id))->handle(app(\App\Support\ChatwootSender::class));
        Storage::disk('local')->assertExists($b->media_path);
        (new SendTransmissionRecipient($r2->id))->handle(app(\App\Support\ChatwootSender::class));
        Storage::disk('local')->assertMissing($b->media_path);
        $this->assertNotNull($b->fresh()->media_removed_at);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages') && $req->hasFile('attachments[]', null, 'recado.wav') && collect($req->data())->firstWhere('name', 'content')['contents'] === 'Escute');

        // validação do formulário
        Http::fake(['*/labels' => Http::response(['payload' => [['title' => 'x']]]), '*' => Http::response(['payload' => [['id' => 9, 'name' => 'Z', 'phone_number' => '+5585999990009']]])]);
        $this->actingAs(User::factory()->create());
        $c = Volt::test('broadcasts.index')->call('toggleLabel', 'x')->set('message', 'Oi');
        $c->set('attachment', UploadedFile::fake()->createWithContent('recado.wav', file_get_contents($this->wav())))->call('review')->assertHasNoErrors('attachment');
        $exe = tempnam(sys_get_temp_dir(), 'ex').'.mp3';
        file_put_contents($exe, 'MZ'.str_repeat("\x90", 500).'This program cannot be run in DOS mode');
        $c->set('attachment', UploadedFile::fake()->createWithContent('musica.mp3', file_get_contents($exe)))->call('review')->assertHasErrors('attachment');
    }
}

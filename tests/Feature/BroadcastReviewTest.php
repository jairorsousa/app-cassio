<?php

namespace Tests\Feature;

use App\Domains\Broadcasts\Models\TransmissionBatch;
use App\Domains\Broadcasts\Models\TransmissionRecipient;
use App\Jobs\SendTransmissionRecipient;
use App\Models\User;
use App\Support\BroadcastComposer as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BroadcastReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_validation(): void
    {
        $this->assertNull(C::normalizeUrl(''));
        $this->assertNull(C::normalizeUrl('   '));
        $this->assertSame('https://instagram.com/x', C::normalizeUrl('instagram.com/x'));
        $this->assertSame('http://a.com.br/p?q=1', C::normalizeUrl('http://a.com.br/p?q=1'));
        foreach (['javascript:alert(1)', 'ftp://a.com', 'mailto:a@b.com', 'data:text/html,x', 'https://', 'nao e link', 'localhost'] as $bad) {
            $this->assertFalse(C::normalizeUrl($bad), $bad);
        }
    }

    public function test_paragraph_formatting(): void
    {
        $this->assertSame("a\n\nb", C::formatText("a\r\nb"));
        $this->assertSame("a\n\nb", C::formatText("a\n\nb"));
        $this->assertSame("a\n\n\nb", C::formatText("a\n\n\nb"));
        $this->assertSame(C::formatText("a\nb\nc"), C::formatText(C::formatText("a\nb\nc")));
        $this->assertSame("a\nb", C::formatText("a\r\nb", false));
        $this->assertSame("Oi\n\nVeja\n\nhttps://x.com/p", C::compose("Oi\nVeja", 'x.com/p'));
    }

    /** parceiros: A,B(mesmo telefone de A),C(sem tel),D(inválido); clientes: A de novo + E. */
    private function broadcastPage()
    {
        $labels = [
            'parceiros' => [['id' => 1, 'name' => 'A', 'phone_number' => '+5585999990001'], ['id' => 2, 'name' => 'B', 'phone_number' => '(85) 99999-0001'], ['id' => 3, 'name' => 'C', 'phone_number' => ''], ['id' => 4, 'name' => 'D', 'phone_number' => '123']],
            'clientes' => [['id' => 1, 'name' => 'A', 'phone_number' => '+5585999990001'], ['id' => 5, 'name' => 'E', 'phone_number' => '+5585988880005']],
        ];
        Http::fake(function ($req) use ($labels) {
            if (str_contains($req->url(), '/labels')) {
                return Http::response(['payload' => [['title' => 'parceiros'], ['title' => 'clientes']]]);
            }
            preg_match('/page=(\d+)/', $req->url(), $m);
            $label = $req->data()['payload'][0]['values'][0] ?? '';

            return Http::response(['payload' => ((int) ($m[1] ?? 1)) === 1 ? ($labels[$label] ?? []) : []]);
        });
        $this->actingAs(User::factory()->create());

        return Volt::test('broadcasts.index');
    }

    public function test_no_label_selected_cannot_start(): void
    {
        Queue::fake();
        $this->broadcastPage()->assertSet('selectedLabels', [])->set('message', 'Oi')->call('review')->assertHasErrors('message')->assertSet('reviewing', false)->call('confirmSend');
        Queue::assertNothingPushed();
        $this->assertSame(0, TransmissionBatch::count());
    }

    public function test_two_labels_dedup_and_preview_equals_payload(): void
    {
        Queue::fake();
        $c = $this->broadcastPage()->call('toggleLabel', 'parceiros')->call('toggleLabel', 'clientes')
            ->set('message', "Olá\nNovidade")->set('link', 'instagram.com/cassio')->set('attachment', UploadedFile::fake()->image('foto.png'));
        $expected = "Olá\n\nNovidade\n\nhttps://instagram.com/cassio\n\nSe não quiser mais receber estas mensagens, responda SAIR.";
        $c->call('review')->assertSet('reviewing', true)->assertSeeHtml('data-review-total>2<')->assertSeeHtml('data-review-dups>2<')->assertSee('foto.png');
        $this->assertStringContainsString(e($expected), $c->html());
        Queue::assertNothingPushed();
        $c->call('confirmSend');
        $b = TransmissionBatch::sole();
        $this->assertSame($expected, $b->body);
        $this->assertSame(2, $b->recipients_count);
        $this->assertSame(2, $b->duplicates_omitted);
        $this->assertSame(2, $b->invalid_omitted);
        $this->assertSame(['+5585988880005', '+5585999990001'], TransmissionRecipient::get()->pluck('phone')->sort()->values()->all());
        Queue::assertPushed(SendTransmissionRecipient::class, 2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'messages') || str_contains($r->url(), '/conversations'));
    }

    public function test_cannot_bypass_confirmation(): void
    {
        Queue::fake();
        $c = $this->broadcastPage()->call('toggleLabel', 'clientes')->set('message', 'Oi');
        $c->call('confirmSend');
        $c->call('send')->assertSet('reviewing', true);
        Queue::assertNothingPushed();
        $c->set('message', 'Oi mudou')->assertSet('reviewing', false)->call('confirmSend');
        $c->call('review')->call('toggleLabel', 'parceiros')->assertSet('reviewing', false)->call('confirmSend');
        Queue::assertNothingPushed();
        $c->call('review')->call('confirmSend')->call('review')->call('confirmSend');
        $this->assertSame(1, TransmissionBatch::count());
    }

    public function test_bad_link_blocks_review(): void
    {
        Queue::fake();
        $this->broadcastPage()->call('toggleLabel', 'clientes')->set('message', 'Oi')->set('link', 'javascript:alert(1)')->call('review')->assertHasErrors('link')->assertSet('reviewing', false);
        Queue::assertNothingPushed();
    }
}

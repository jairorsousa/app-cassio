<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastMessage;
use App\Models\User;
use App\Support\BroadcastComposer as C;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function broadcastPage(int $contacts = 3)
    {
        Http::fake(function ($req) use ($contacts) {
            if (str_contains($req->url(), 'labels')) {
                return Http::response(['payload' => [['title' => 'parceiros']]]);
            }
            preg_match('/page=(\d+)/', $req->url(), $m);
            $all = [['id' => 1, 'name' => 'A', 'phone_number' => '+5585999990001'], ['id' => 2, 'name' => 'B', 'phone_number' => '+5585999990001'], ['id' => 3, 'name' => 'C', 'phone_number' => ''], ['id' => 4, 'name' => 'D', 'phone_number' => '123']];

            return Http::response(['payload' => ((int) ($m[1] ?? 1)) === 1 ? array_slice($all, 0, $contacts) : []]);
        });
        $this->actingAs(User::factory()->create());

        return Volt::test('broadcasts.index');
    }

    public function test_review_shows_counts_and_preview_equals_payload(): void
    {
        Queue::fake();
        $c = $this->broadcastPage(4)->set('message', "Olá\nNovidade")->set('link', 'instagram.com/cassio')->set('attachment', UploadedFile::fake()->image('foto.png'));
        $expected = "Olá\n\nNovidade\n\nhttps://instagram.com/cassio";
        $c->assertSeeHtml('data-broadcast-preview')->call('review')->assertSet('reviewing', true)
            ->assertSeeHtml('data-review-total>4<')->assertSee('Sem telefone')->assertSee('foto.png');
        $this->assertStringContainsString(e($expected), $c->html());
        Queue::assertNothingPushed();
        $c->call('confirmSend');
        Queue::assertPushed(SendBroadcastMessage::class, function ($job) use ($expected) {
            $r = new \ReflectionClass($job);
            $msg = $r->getProperty('message'); $msg->setAccessible(true);
            $att = $r->getProperty('attachment'); $att->setAccessible(true);
            $ids = $r->getProperty('contactIds'); $ids->setAccessible(true);

            return $msg->getValue($job) === $expected && ! empty($att->getValue($job)['path']) && $ids->getValue($job) === [1, 2, 3, 4];
        });
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'messages') || str_contains($r->url(), 'conversations'));
    }

    public function test_cannot_bypass_confirmation(): void
    {
        Queue::fake();
        $c = $this->broadcastPage()->set('message', 'Oi');
        $c->call('confirmSend');
        $c->call('send')->assertSet('reviewing', true);
        Queue::assertNothingPushed();
        $c->set('message', 'Oi mudou')->assertSet('reviewing', false)->call('confirmSend');
        Queue::assertNothingPushed();
        $c->call('review')->call('confirmSend')->call('review')->call('confirmSend');
        Queue::assertPushed(SendBroadcastMessage::class, 1);
    }

    public function test_bad_link_blocks_review(): void
    {
        Queue::fake();
        $this->broadcastPage()->set('message', 'Oi')->set('link', 'javascript:alert(1)')->call('review')->assertHasErrors('link')->assertSet('reviewing', false);
        Queue::assertNothingPushed();
    }
}

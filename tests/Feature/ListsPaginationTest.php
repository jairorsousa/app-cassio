<?php

namespace Tests\Feature;

use App\Domains\Banking\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ListsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeChatwoot(int $n): void
    {
        config(['services.chatwoot.base_url' => 'https://cw.test', 'services.chatwoot.url' => 'https://cw.test']);
        $contacts = collect(range(1, $n))->map(fn ($i) => ['id' => $i, 'name' => 'Contato '.str_pad($i, 3, '0', STR_PAD_LEFT), 'phone_number' => '+55850000'.str_pad($i, 4, '0', STR_PAD_LEFT), 'email' => "c$i@x.test", 'custom_attributes' => ['big' => str_repeat('x', 500)]])->all();
        Http::fake(function ($req) use ($contacts) {
            if (str_contains($req->url(), 'labels')) {
                return Http::response(['payload' => [['title' => 'parceiros']]]);
            }
            preg_match('/page=(\d+)/', $req->url(), $m);
            $p = (int) ($m[1] ?? 1);

            return Http::response(['payload' => array_slice($contacts, ($p - 1) * 15, 15)]);
        });
    }

    public function test_broadcast_contacts_paginated_filtered_and_slim(): void
    {
        $this->fakeChatwoot(47);
        $this->actingAs(User::factory()->create());
        $c = Volt::test('broadcasts.index')->assertSee('Marque uma ou mais etiquetas.')->call('toggleLabel', 'parceiros');
        $c->assertSee('47 pessoa(s) únicas vão receber')->assertSee('Contato 001')->assertSee('Contato 020')->assertDontSee('Contato 021')->assertSee('Página 1 de 3');
        $this->assertSame(['id', 'name', 'phone_number'], array_keys($c->get('labelContacts')['parceiros'][0]));
        $this->assertCount(47, $c->get('contacts'));
        $c->call('contactPageTo', 3)->assertSee('Contato 047')->assertDontSee('Contato 001');
        $c->set('contactSearch', 'Contato 04')->assertSet('contactPage', 1)->assertSee('Contato 040')->assertDontSee('Contato 001')->assertDontSeeHtml('data-contact-pages');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'messages'));
    }

    public function test_categories_paginate_roots_and_lazy_children(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (range(1, 23) as $i) {
            $p = Category::create(['name' => 'Raiz '.str_pad($i, 2, '0', STR_PAD_LEFT), 'type' => 'expense', 'icon' => 'label', 'color' => '#ff6f00', 'status' => true]);
        }
        $parent = Category::where('name', 'Raiz 01')->first();
        Category::create(['name' => 'Filha Unica', 'type' => 'expense', 'icon' => 'label', 'color' => '#ff6f00', 'status' => true, 'parent_id' => $parent->id]);
        $c = Volt::test('banking.categories.index')
            ->assertSee('Despesas (24)')->assertSee('Raiz 20')->assertDontSee('Raiz 21')->assertSeeHtml('data-pages="despesas"')
            ->assertSee('Ver 1 subcategoria(s)')->assertDontSee('Filha Unica');
        $c->call('toggle', $parent->id)->assertSee('Filha Unica')->assertSee('Ocultar 1 subcategoria(s)');
        $c->call('toggle', $parent->id)->assertDontSee('Filha Unica');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandVerticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_uses_new_brand_animated_logo_and_icons(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('data-vertice-logo', $html);
        $this->assertStringContainsString('prefers-reduced-motion:no-preference', $html);
        $this->assertStringContainsString('href="/favicon.svg?v=vcm1"', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringContainsString('site.webmanifest', $html);
        $this->assertStringContainsString('Vértice', $html);
        $this->assertStringNotContainsString('Cassio Finance', $html);
    }

    public function test_header_uses_new_logo_and_no_old_name(): void
    {
        $this->actingAs(User::factory()->create());
        $html = $this->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('data-vertice-logo', $html);
        $this->assertStringNotContainsString('Cassio Finance', $html);
    }

    public function test_static_brand_files_exist(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'favicon-16.png', 'favicon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'brand/vertice-cm-horizontal.svg', 'brand/vertice-cm-icone-animado.svg'] as $f) {
            $this->assertFileExists(public_path($f), $f);
        }
        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true);
        $this->assertSame('Vértice CM', $manifest['name']);
        $this->assertStringNotContainsString('<animate', file_get_contents(public_path('favicon.svg')));
        $this->assertStringNotContainsString('@keyframes', file_get_contents(public_path('favicon.svg')));
    }
}

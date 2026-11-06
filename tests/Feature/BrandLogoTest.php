<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BrandLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $w = 512, int $h = 512): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start(); imagepng($im); $data = ob_get_clean();
        $p = tempnam(sys_get_temp_dir(), 'lg').'.png'; file_put_contents($p, $data);

        return new UploadedFile($p, 'logo.png', 'image/png', null, true);
    }

    public function test_rejects_invalid_images(): void
    {
        Storage::fake('local');
        $u = User::factory()->create();
        $this->actingAs($u)->post(route('settings.logo.store'), ['logo' => $this->png(300, 300)])->assertSessionHasErrors('logo', null, 'logo');
        $this->actingAs($u)->post(route('settings.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('x.png', '<svg onload=alert(1)>')])->assertSessionHasErrors('logo', null, 'logo');
        $this->assertFalse(BrandLogo::exists($u));
    }

    public function test_upload_is_per_user_and_removal_only_own(): void
    {
        Storage::fake('local');
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->post(route('settings.logo.store'), ['logo' => $this->png()])->assertRedirect();
        $this->assertTrue(BrandLogo::exists($a));
        $this->assertFalse(BrandLogo::exists($b));
        $this->actingAs($a)->get(route('settings.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($b)->get(route('settings.logo'))->assertNotFound();
        $this->actingAs($a)->get('/configuracoes')->assertSee('alt="Logo"', false);
        $this->actingAs($b)->delete(route('settings.logo.destroy'));
        $this->assertTrue(BrandLogo::exists($a));
        $this->actingAs($a)->delete(route('settings.logo.destroy'));
        $this->assertFalse(BrandLogo::exists($a));
    }

    public function test_gear_link_in_main_nav_after_last_item(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/configuracoes')->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Configurações"', $html);
        $this->assertGreaterThan(strpos($html, 'Transmissão'), strpos($html, 'data-nav-settings'));
    }

    public function test_guest_cannot_upload(): void
    {
        $this->post(route('settings.logo.store'))->assertRedirect('/login');
    }
}

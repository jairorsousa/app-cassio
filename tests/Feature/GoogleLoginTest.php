<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\GoogleAuth\IdentityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeGoogleIdentity implements IdentityProvider
{
    public ?array $identity = null;
    public bool $isConfigured = true;
    public ?string $lastVerifier = null;

    public function configured(): bool { return $this->isConfigured; }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return 'https://accounts.example.test/auth?state='.$state.'&cc='.$codeChallenge;
    }

    public function verifiedIdentity(string $code, string $codeVerifier): ?array
    {
        $this->lastVerifier = $codeVerifier;
        return $this->identity;
    }
}

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleIdentity $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeGoogleIdentity();
        $this->app->instance(IdentityProvider::class, $this->fake);
    }

    private function start(): string
    {
        $r = $this->get('/login/google');
        $r->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame(64, strlen($q['state']));
        $v = session('google_login.verifier');
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='), $q['cc']);
        return $q['state'];
    }

    private function id(string $email, string $sub = 'sub-1', bool $verified = true): array
    {
        return ['sub' => $sub, 'email' => $email, 'email_verified' => $verified];
    }

    public function test_usuario_existente_entra_e_vincula_subject(): void
    {
        $u = User::factory()->create(['email' => 'dono@example.test']);
        $u->forceFill(['modules' => ['dashboard', 'contacts']])->save();
        $this->fake->identity = $this->id('Dono@Example.test');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc')->assertRedirect();
        $this->assertAuthenticatedAs($u);
        $this->assertNotNull($this->fake->lastVerifier);
        $u->refresh();
        $this->assertSame('sub-1', $u->google_sub);
        $this->assertSame(['dashboard', 'contacts'], $u->modules);
        // segunda vez com o mesmo subject
        auth()->logout();
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertAuthenticatedAs($u);
    }

    public function test_email_desconhecido_nao_cria_conta(): void
    {
        User::factory()->create(['email' => 'dono@example.test']);
        $this->fake->identity = $this->id('estranho@example.test');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_bloqueado_rejeitado(): void
    {
        $u = User::factory()->create(['email' => 'b@example.test']);
        $u->forceFill(['status' => 'blocked', 'blocked_at' => now()])->save();
        $this->fake->identity = $this->id('b@example.test');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($u->fresh()->google_sub);
    }

    public function test_email_nao_verificado_rejeitado(): void
    {
        User::factory()->create(['email' => 'n@example.test']);
        $this->fake->identity = $this->id('n@example.test', 'sub-1', false);
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertGuest();
    }

    public function test_state_errado_ou_ausente_ou_expirado_negado(): void
    {
        User::factory()->create(['email' => 's@example.test']);
        $this->fake->identity = $this->id('s@example.test');
        $this->get('/login/google/callback?state=x&code=abc')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->start();
        $this->get('/login/google/callback?state=errado&code=abc');
        $this->assertGuest();
        $state = $this->start();
        $this->travel(11)->minutes();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertGuest();
    }

    public function test_state_nao_pode_ser_reutilizado(): void
    {
        $u = User::factory()->create(['email' => 'r@example.test']);
        $this->fake->identity = $this->id('r@example.test');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertAuthenticatedAs($u);
        auth()->logout();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertGuest();
    }

    public function test_subject_de_outro_usuario_nao_vincula(): void
    {
        $a = User::factory()->create(['email' => 'a@example.test']);
        $a->forceFill(['google_sub' => 'sub-A'])->save();
        $b = User::factory()->create(['email' => 'b2@example.test']);
        $this->fake->identity = $this->id('b2@example.test', 'sub-A');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertGuest();
        $this->assertNull($b->fresh()->google_sub);
        // usuário já vinculado a outro subject também é recusado
        $this->fake->identity = $this->id('a@example.test', 'sub-Z');
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&code=abc');
        $this->assertGuest();
        $this->assertSame('sub-A', $a->fresh()->google_sub);
    }

    public function test_cancelamento_volta_ao_login(): void
    {
        $state = $this->start();
        $this->get('/login/google/callback?state='.$state.'&error=access_denied')
            ->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_botao_aparece_so_quando_configurado(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continuar com Google');
        $this->fake->isConfigured = false;
        $this->get('/login')->assertOk()->assertDontSee('Continuar com Google');
        $this->get('/login/google')->assertRedirect(route('login'));
    }

    public function test_usuario_logado_e_redirecionado(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/login/google')->assertRedirect();
        $this->assertNull(session('google_login'));
    }

    public function test_provider_real_le_config_com_fallback_da_agenda(): void
    {
        config(['google-auth.client_id' => 'cid', 'google-auth.client_secret' => 'sec', 'google-auth.redirect_uri' => 'https://app.test/login/google/callback']);
        $p = new \App\Support\GoogleAuth\GoogleClientIdentityProvider();
        $this->assertTrue($p->configured());
        $url = $p->authorizationUrl('st', 'cc');
        $this->assertStringContainsString('scope=openid%20email%20profile', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringNotContainsString('sec', $url);
    }
}

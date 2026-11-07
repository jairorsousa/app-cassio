<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InviteLinkDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    public function test_token_is_encrypted_and_current_link_shown_everywhere(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g = $svc->createGroup($admin, ['name' => 'Churrasco', 'type' => 'amigos']);
        $m = $svc->addMember($admin, $g, 'Sandro', 'sandro@example.com');
        $link = $svc->inviteMember($admin, $m);
        $inv = UserInvitation::first();
        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->assertNotSame($token, $inv->token_encrypted);
        $this->assertSame($token, Crypt::decryptString($inv->token_encrypted));
        $this->assertSame($link, $inv->currentLink());
        $this->assertArrayNotHasKey('token_encrypted', $inv->toArray());

        $settings = Volt::test('settings.users');
        $this->assertGreaterThanOrEqual(2, substr_count($settings->html(), e($link)));
        $settings->assertSee('Link copiado de Sandro')->assertSeeHtml('data-copy-link');
        Volt::test('divisions.show', ['group' => $g])->assertSee($link)->assertSee('Link copiado de Sandro');

        // Novo link: o antigo some da tela.
        $new = $svc->inviteMember($admin, $m->fresh());
        Volt::test('settings.users')->assertSee($new)->assertDontSee($link);
    }

    public function test_revoked_expired_and_legacy_invites_never_show_link(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        [$inv, $token] = UserAccessService::invite($admin, 'Heleno', 'heleno@example.com', ['divisoes']);
        $link = route('invite.accept', ['token' => $token]);

        $inv->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull($inv->fresh()->currentLink());
        Volt::test('settings.users')->assertDontSee($link)->assertSee('Sem link gerado');

        $inv->forceFill(['expires_at' => now()->addDay()])->save();
        UserAccessService::revoke($admin, $inv->id);
        $this->assertNull($inv->fresh()->token_encrypted);
        Volt::test('settings.users')->assertDontSee($link)->assertSee('Sem link gerado')->assertSeeHtml('data-generate-link');

        // Convite antigo, só com hash: pede para gerar novo link.
        UserInvitation::create(['name' => 'Antigo', 'email' => 'antigo@example.com', 'modules' => ['divisoes'], 'token_hash' => UserInvitation::hashToken('x'), 'expires_at' => now()->addDays(3), 'invited_by' => $admin->id]);
        Volt::test('settings.users')->assertSee('Gere um novo link');

        // Gerar link pelo botão mostra o link atual.
        $page = Volt::test('settings.users')->call('regenerate', $inv->id);
        $this->assertSame($page->get('generatedLink'), $inv->fresh()->currentLink());
    }

    public function test_accepted_invite_clears_token(): void
    {
        $admin = $this->admin();
        [$inv, $token] = UserAccessService::invite($admin, 'Ana', 'ana@example.com', ['divisoes']);
        UserAccessService::accept($token, 'SenhaForte123!');
        $this->assertNull($inv->fresh()->token_encrypted);
        $this->assertNull($inv->fresh()->currentLink());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Access;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true])->save();

        return $u;
    }

    private function limited(array $modules): User
    {
        $u = User::factory()->create();
        $u->forceFill(['modules' => $modules])->save();

        return $u->fresh();
    }

    private function tokenFrom(string $link): string
    {
        return basename(parse_url($link, PHP_URL_PATH));
    }

    public function test_migracao_deixa_id1_admin_e_demais_com_todos_os_modulos(): void
    {
        // Simula a regra do backfill nos dados criados pelo factory.
        $u1 = User::factory()->create();
        $this->assertSame(array_keys(Access::MODULES), $u1->modules);
        $this->assertFalse($u1->isAdmin());
        $this->assertSame('active', $u1->status);
        $sql = file_get_contents(database_path('migrations/2026_11_06_700000_users_access_invitations.php'));
        $this->assertStringContainsString("where('id', 1)->update(['is_admin' => true])", $sql);
    }

    public function test_modulo_nao_liberado_some_do_menu_e_bloqueia_rota_e_livewire(): void
    {
        $u = $this->limited(['financeiro']);
        $this->actingAs($u);
        $this->get(route('banking.dashboard'))->assertOk()->assertSee('Financeiro')->assertDontSee('href="'.route('writs.kanban').'"', false);
        $this->get(route('writs.kanban'))->assertForbidden();
        $this->get(route('bens.index'))->assertForbidden();
        $this->get(route('broadcasts.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('banking.dashboard'));
        $this->get('/')->assertRedirect();
        $this->get(route('settings'))->assertOk();
        $this->get(route('settings.users'))->assertForbidden();
        Volt::test('writs.kanban')->assertForbidden();
        Volt::test('bens.index')->assertForbidden();
        Volt::test('banking.dashboard')->assertOk();
    }

    public function test_componente_embutido_compartilhado_e_livewire_de_outro_modulo(): void
    {
        $this->actingAs($this->limited(['dashboard']));
        Volt::test('banking.regime-switch')->assertOk();
        Volt::test('broadcasts.history')->assertForbidden();
        Volt::test('settings.users')->assertForbidden();
    }

    public function test_so_admin_acessa_usuarios_e_nao_promove_via_payload(): void
    {
        $this->actingAs($this->limited(array_keys(Access::MODULES)));
        $this->get(route('settings.users'))->assertForbidden();
        try {
            UserAccessService::invite(auth()->user(), 'X', 'x@example.com', ['financeiro']);
            $this->fail('não-admin convidou');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get(route('settings.users'))->assertOk()->assertSee('Usuários e acessos');
        $this->get(route('settings'))->assertSee(route('settings.users'), false);
        $u = User::factory()->create();
        $u->update(['is_admin' => true, 'modules' => ['x']]); // fora do fillable: ignorado
        $this->assertFalse($u->fresh()->isAdmin());
        $this->assertSame(array_keys(Access::MODULES), $u->fresh()->modules);
    }

    public function test_convite_uso_unico_email_imutavel_e_senha_12(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $c = Volt::test('settings.users')->set('inviteName', 'Mateus')->set('inviteEmail', 'Mateus@Example.com')->set('inviteModules', ['financeiro', 'contatos'])->call('invite')->assertHasNoErrors();
        $link = $c->get('generatedLink');
        $token = $this->tokenFrom($link);
        $inv = UserInvitation::sole();
        $this->assertSame('mateus@example.com', $inv->email);
        $this->assertNotSame($token, $inv->token_hash);
        $this->assertSame(64, strlen($inv->token_hash));
        $this->assertStringNotContainsString($token, json_encode(UserInvitation::first()->getAttributes()));
        $this->assertNull(Volt::test('settings.users')->get('generatedLink'), 'Link não fica salvo na tela.');

        Auth::logout();
        $this->get(route('invite.accept', ['token' => $token]))->assertOk()->assertSee('mateus@example.com')->assertSee('não pode ser alterado');
        Volt::test('invite.accept', ['token' => $token])->set('password', 'curta123')->set('password_confirmation', 'curta123')->call('accept')->assertHasErrors('password');
        Volt::test('invite.accept', ['token' => $token])->set('password', 'senha-bem-longa-1')->set('password_confirmation', 'senha-bem-longa-1')->call('accept')->assertHasNoErrors()->assertRedirect(route('login'));
        $u = User::where('email', 'mateus@example.com')->sole();
        $this->assertSame(['financeiro', 'contatos'], $u->modules);
        $this->assertFalse($u->isAdmin());
        $this->assertNotNull($inv->fresh()->accepted_at);
        $this->assertTrue(Auth::attempt(['email' => 'mateus@example.com', 'password' => 'senha-bem-longa-1']));
        Auth::logout();

        // Uso único
        Volt::test('invite.accept', ['token' => $token])->assertSee('Convite inválido')->set('password', 'outra-senha-longa')->set('password_confirmation', 'outra-senha-longa')->call('accept')->assertHasErrors('password');
        $this->assertSame(1, User::where('email', 'mateus@example.com')->count());
        $this->get(route('invite.accept', ['token' => 'tokenfalso']))->assertOk()->assertSee('Convite inválido');
    }

    public function test_email_ja_usado_revogado_expirado_e_regenerado(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'ja@example.com']);
        try {
            UserAccessService::invite($admin, 'Ja', 'JA@example.com', ['financeiro']);
            $this->fail('convidou e-mail existente');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inviteEmail', $e->errors());
        }

        [$inv, $t1] = UserAccessService::invite($admin, 'Ana', 'ana@example.com', ['contatos']);
        $t2 = UserAccessService::regenerate($admin, $inv->id);
        $this->assertNull(UserInvitation::findUsable($t1), 'Link antigo invalidado.');
        $this->assertNotNull(UserInvitation::findUsable($t2));
        UserAccessService::revoke($admin, $inv->id);
        $this->assertNull(UserInvitation::findUsable($t2));
        $t3 = UserAccessService::regenerate($admin, $inv->id);
        $this->travel(8)->days();
        $this->assertNull(UserInvitation::findUsable($t3), 'Expira em 7 dias.');
        $this->assertSame('expired', $inv->fresh()->status());

        // E-mail que virou conta depois do convite: não ativa.
        $this->travelBack();
        [$inv2, $t4] = UserAccessService::invite($admin, 'Bia', 'bia@example.com', ['contatos']);
        User::factory()->create(['email' => 'bia@example.com']);
        try {
            UserAccessService::accept($t4, 'senha-bem-longa-1');
            $this->fail('ativou e-mail já usado');
        } catch (ValidationException) {
            $this->assertNull($inv2->fresh()->accepted_at);
        }
    }

    public function test_bloqueio_derruba_sessao_e_reativar_preserva_modulos(): void
    {
        $admin = $this->admin();
        $u = $this->limited(['financeiro', 'contatos']);
        $this->actingAs($u)->get(route('contacts.index'))->assertOk();
        UserAccessService::block($admin, $u->id);
        $this->actingAs($u->fresh())->get(route('contacts.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(Access::allows($u->fresh(), ['contatos']));

        // Login de conta bloqueada é recusado.
        $u->forceFill(['password' => 'senha-bem-longa-1'])->save();
        Volt::test('pages.auth.login')->set('form.email', $u->email)->set('form.password', 'senha-bem-longa-1')->call('login')->assertHasErrors('form.email');
        $this->assertGuest();

        UserAccessService::reactivate($admin, $u->id);
        $this->assertSame(['financeiro', 'contatos'], $u->fresh()->modules);
        $this->actingAs($u->fresh())->get(route('contacts.index'))->assertOk();
    }

    public function test_admin_nao_bloqueia_nem_restringe_a_si_e_edicao_vale_na_proxima_acao(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Volt::test('settings.users')->call('block', $admin->id)->assertHasErrors('users');
        Volt::test('settings.users')->set('editModules.'.$admin->id, [])->call('saveModules', $admin->id)->assertHasErrors('users');
        $this->assertSame('active', $admin->fresh()->status);

        $u = $this->limited(['financeiro']);
        Volt::test('settings.users')->set('editModules.'.$u->id, ['contatos'])->call('saveModules', $u->id)->assertHasNoErrors();
        $this->actingAs($u->fresh())->get(route('banking.dashboard'))->assertForbidden();
        $this->get(route('contacts.index'))->assertOk();
        $this->actingAs($admin);
        Volt::test('settings.users')->set('editModules.'.$u->id, [])->call('saveModules', $u->id)->assertHasErrors('modules');
    }
}

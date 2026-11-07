<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DivisionsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    public function test_module_invite_shows_in_settings_and_regenerates_same_record(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Churrasco', 'type' => 'amigos']);
        $m = $this->svc()->addMember($admin, $g, 'Sandro', 'sandro@example.com');
        $first = $this->svc()->inviteMember($admin, $m);
        $this->assertSame(1, UserInvitation::count());

        $page = Volt::test('settings.users')->assertSeeHtml('data-divisions-access')->assertSee('sandro@example.com')
            ->assertSee('Convite pendente')->assertSee('Churrasco')->assertSee('Divisões');
        $page->call('divLink', 'sandro@example.com')->assertHasNoErrors();
        $this->assertSame(1, UserInvitation::count());
        $second = $page->get('generatedLink');
        $this->assertNotSame($first, $second);
        $this->assertNull(UserInvitation::findUsable(basename(parse_url($first, PHP_URL_PATH))));
        $this->assertNotNull(UserInvitation::findUsable(basename(parse_url($second, PHP_URL_PATH))));

        // O botão do módulo também reaproveita o mesmo registro.
        $this->svc()->inviteMember($admin, $m->fresh());
        $this->assertSame(1, UserInvitation::count());
    }

    public function test_settings_invite_with_divisions_module_and_groups(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Família', 'type' => 'familia']);
        Volt::test('settings.users')->set('inviteName', 'Heleno')->set('inviteEmail', 'heleno@example.com')
            ->set('inviteModules', ['divisoes'])->assertSeeHtml('data-invite-groups')->set('inviteGroups', [(string) $g->id])
            ->call('invite')->assertHasNoErrors()->assertSet('generatedFor', 'Heleno');
        $this->assertSame(['divisoes'], UserInvitation::first()->modules);
        $this->assertTrue($g->members()->where('email', 'heleno@example.com')->exists());
    }

    public function test_add_and_remove_person_from_groups_in_settings(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g1 = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos']);
        $g2 = $this->svc()->createGroup($admin, ['name' => 'Escritório', 'type' => 'sociedade']);
        $this->svc()->addMember($admin, $g1, 'Sandro', 'sandro@example.com');
        $page = Volt::test('settings.users')->set('divAddGroup.'.md5('sandro@example.com'), (string) $g2->id)->call('divAddToGroup', 'sandro@example.com')->assertHasNoErrors();
        $member = $g2->members()->where('email', 'sandro@example.com')->first();
        $this->assertNotNull($member);
        $page->call('divRemoveFromGroup', $member->id)->assertHasNoErrors();
        $this->assertFalse($g2->members()->where('email', 'sandro@example.com')->exists());
    }

    public function test_cannot_remove_member_with_history(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos']);
        $m = $this->svc()->addMember($admin, $g, 'Sandro', 'sandro@example.com');
        $owner = $g->fresh()->ownerMember();
        $this->svc()->saveExpense($admin, $g->fresh(), ['description' => 'Gás', 'date' => '2026-11-01', 'amount' => '50', 'paid_by_member_id' => $owner->id, 'split_mode' => 'equal', 'inputs' => [$owner->id => 1, $m->id => 1]]);
        Volt::test('settings.users')->call('divRemoveFromGroup', $m->id)->assertHasErrors('divisions');
        $this->assertNotNull(DivisionMember::find($m->id));
    }

    public function test_revoke_access_for_pending_invite_and_accepted_user(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos']);
        $m = $this->svc()->addMember($admin, $g, 'Sandro', 'sandro@example.com');
        $link = $this->svc()->inviteMember($admin, $m);
        Volt::test('settings.users')->call('divRevoke', 'sandro@example.com')->assertHasNoErrors();
        $this->assertSame(0, UserInvitation::count(), 'Convite revogado sem conta é apagado.');
        $this->assertNull(UserInvitation::findUsable(basename(parse_url($link, PHP_URL_PATH))));

        // Novo link no mesmo registro, aceito, depois acesso revogado: conta só com Divisões fica bloqueada.
        $page = Volt::test('settings.users')->call('divLink', 'sandro@example.com');
        $user = UserAccessService::accept(basename(parse_url($page->get('generatedLink'), PHP_URL_PATH)), 'SenhaForte123!');
        $this->assertSame(1, UserInvitation::count());
        Volt::test('settings.users')->assertSee('Convite aceito · com acesso')->call('divRevoke', 'sandro@example.com');
        $this->assertSame('blocked', $user->fresh()->status);
        $this->assertNotNull(DivisionMember::find($m->id));

        // Usuário com outros módulos só perde Divisões.
        $other = User::factory()->create(['email' => 'ana@example.com']);
        $other->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['contatos', 'divisoes']])->save();
        Volt::test('settings.users')->call('divRevoke', 'ana@example.com');
        $this->assertSame(['contatos'], $other->fresh()->modules);
        $this->assertSame('active', $other->fresh()->status);
    }
}

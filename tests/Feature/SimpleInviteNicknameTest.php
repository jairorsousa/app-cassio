<?php

namespace Tests\Feature;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SimpleInviteNicknameTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    public function test_settings_invite_email_required_nickname_and_phone_optional(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = app(DivisionService::class)->createGroup($admin, ['name' => 'CDL', 'type' => 'familia']);
        Volt::test('settings.users')->assertSee('Apelido (opcional)')->assertSee('Celular (opcional)')
            ->set('inviteEmail', '')->call('invite')->assertHasErrors('inviteEmail');
        Volt::test('settings.users')->set('inviteEmail', 'joao.silva@example.com')->set('invitePhone', '123')->set('inviteModules', ['divisoes'])->call('invite')->assertHasErrors('invitePhone');
        Volt::test('settings.users')->set('inviteEmail', 'joao.silva@example.com')->set('invitePhone', '(85) 99999-8888')
            ->set('inviteModules', ['divisoes'])->set('inviteGroups', [(string) $g->id])->call('invite')->assertHasNoErrors();
        $inv = UserInvitation::first();
        $this->assertSame('joao.silva', $inv->name, 'sem apelido usa o e-mail');
        $this->assertSame('+5585999998888', $inv->phone);
        $this->assertSame('+5585999998888', $g->members()->where('email', 'joao.silva@example.com')->value('phone'));
    }

    public function test_division_new_participant_requires_email_nickname_optional(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = app(DivisionService::class)->createGroup($admin, ['name' => 'CDL', 'type' => 'familia']);
        Volt::test('divisions.show', ['group' => $g])->assertSee('Apelido (opcional)')
            ->set('memberName', 'Fulano')->call('addMember')->assertHasErrors('memberEmail')
            ->set('memberName', '')->set('memberEmail', 'maria@example.com')->call('addMember')->assertHasNoErrors(['memberEmail', 'memberName']);
        $this->assertSame('maria', $g->members()->where('email', 'maria@example.com')->value('name'));
    }

    public function test_profile_requires_name_birth_phone_and_real_name_replaces_nickname(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = app(DivisionService::class)->createGroup($admin, ['name' => 'CDL', 'type' => 'familia']);
        [$inv, $token] = UserAccessService::invite($admin, 'Zé', 'ze@example.com', ['divisoes'], '(85) 98888-7777');
        app(DivisionService::class)->addMember($admin, $g, 'Zé', 'ze@example.com');
        $u = UserAccessService::accept($token, 'SenhaForte123!');
        $u->forceFill(['profile_completed_at' => null])->save();
        $this->actingAs($u->fresh());
        $page = Volt::test('profile.complete')->assertSet('phone', '(85) 98888-7777')
            ->set('name', '')->set('phone', '')->set('birth_date', null)->call('save')->assertHasErrors(['name', 'phone', 'birth_date']);
        foreach (['name' => 'José da Silva', 'phone' => '(85) 98888-7777', 'document' => '529.982.247-25', 'birth_date' => '1980-05-10', 'zip_code' => '60000-000',
            'street' => 'Rua A', 'number' => '10', 'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE'] as $k => $v) {
            $page->set($k, $v);
        }
        $page->call('save')->assertHasNoErrors();
        $this->assertSame('José da Silva', $u->fresh()->name);
        $this->assertSame('José da Silva', DivisionMember::where('email', 'ze@example.com')->value('name'));
        $this->assertSame('José da Silva', Contact::find($u->fresh()->contact_id)->name);
        $this->assertSame('José da Silva', $inv->fresh()->name);
    }
}

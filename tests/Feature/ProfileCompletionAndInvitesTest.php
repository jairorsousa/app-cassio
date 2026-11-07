<?php

namespace Tests\Feature;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileCompletionAndInvitesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function guest(string $email = 'heleno@example.com'): User
    {
        $u = User::factory()->create(['name' => 'Heleno', 'email' => $email, 'profile_completed_at' => null]);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();

        return $u;
    }

    private function valid(): array
    {
        return ['name' => 'Heleno Mota', 'phone' => '(85) 99999-8888', 'document' => '529.982.247-25', 'birth_date' => '1980-05-10',
            'zip_code' => '60000-000', 'street' => 'Rua A', 'number' => '10', 'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE'];
    }

    public function test_invited_user_is_redirected_until_profile_completed_admin_exempt(): void
    {
        $this->actingAs($this->admin())->get(route('divisions.index'))->assertOk();
        $g = $this->guest();
        $this->actingAs($g);
        $this->get(route('divisions.index'))->assertRedirect(route('profile.complete'));
        $this->get(route('profile.complete'))->assertOk()->assertSee('Complete seu cadastro');
        $this->post(route('logout'))->assertRedirect();
    }

    public function test_prefill_validation_and_save_creates_linked_contact_and_syncs_phone(): void
    {
        $admin = $this->admin();
        $svc = app(DivisionService::class);
        $grp = $svc->createGroup($admin, ['name' => 'Família', 'type' => 'familia']);
        $m = $svc->addMember($admin, $grp, 'Heleno', 'heleno@example.com', '(85) 91111-2222');
        $g = $this->guest();
        $this->actingAs($g);
        $page = Volt::test('profile.complete')->assertSet('name', 'Heleno')->assertSet('email', 'heleno@example.com')->assertSet('phone', '(85) 91111-2222');
        $page->set('document', '111.111.111-11')->set('phone', '123')->call('save')->assertHasErrors(['document', 'phone', 'birth_date', 'street']);
        foreach ($this->valid() as $k => $v) {
            $page->set($k, $v);
        }
        $page->call('save')->assertHasNoErrors();
        $g->refresh();
        $this->assertNotNull($g->profile_completed_at);
        $c = Contact::findOrFail($g->contact_id);
        $this->assertSame('Heleno Mota', $c->name);
        $this->assertSame('heleno@example.com', $c->email);
        $this->assertContains('pessoal', $c->roleList());
        $this->assertSame('+5585999998888', $m->fresh()->phone);
        $this->get(route('divisions.index'))->assertOk();

        // Celular alterado em Divisões volta para o Contato.
        $svc->setPersonPhone($admin, 'heleno@example.com', '(85) 97777-6666');
        $this->assertSame('(85) 97777-6666', $c->fresh()->phone);
    }

    public function test_existing_contact_matched_by_cpf_without_duplicate(): void
    {
        $existing = Contact::create(['name' => 'H. Mota', 'document' => '52998224725', 'roles' => ['cedente'], 'type' => 'cedente', 'status' => true]);
        $g = $this->guest();
        $this->actingAs($g);
        $page = Volt::test('profile.complete');
        foreach ($this->valid() as $k => $v) {
            $page->set($k, $v);
        }
        $page->call('save')->assertHasNoErrors();
        $this->assertSame(1, Contact::count());
        $this->assertSame($existing->id, $g->fresh()->contact_id);
        $this->assertEqualsCanonicalizing(['cedente', 'pessoal'], $existing->fresh()->roleList());
    }

    public function test_revoke_deletes_unaccepted_and_lists_hide_revoked_or_expired(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        [$inv] = UserAccessService::invite($admin, 'Errado', 'errado@example.com', ['divisoes']);
        UserAccessService::revoke($admin, $inv->id);
        $this->assertNull(UserInvitation::find($inv->id));
        [$old] = UserAccessService::invite($admin, 'Velho', 'velho@example.com', ['divisoes']);
        $old->forceFill(['expires_at' => now()->subDay()])->save();
        [$ok] = UserAccessService::invite($admin, 'Válido', 'valido@example.com', ['divisoes']);
        $this->assertEquals([$ok->id], UserInvitation::visible()->pluck('id')->all());
        Volt::test('settings.users')->assertDontSee('velho@example.com')->assertDontSee('errado@example.com')->assertSee('valido@example.com');
    }
}

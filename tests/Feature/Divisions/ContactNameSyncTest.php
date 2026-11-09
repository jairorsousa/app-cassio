<?php

namespace Tests\Feature\Divisions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Renomear em Contatos atualiza usuário e participantes do Divisões vinculados (e o "Editar nome" segue funcionando). */
class ContactNameSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_contact_updates_linked_user_and_division_members(): void
    {
        $admin = User::factory()->create(['name' => 'Cassio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $c = Contact::create(['name' => 'Polly', 'email' => 'polly@example.com', 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $u = User::factory()->create(['name' => 'Polly', 'email' => 'polly@example.com']);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes'], 'contact_id' => $c->id])->save();
        $other = Contact::create(['name' => 'Outra', 'email' => 'outra@example.com', 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g1 = $svc->createGroup($admin, ['name' => 'A', 'type' => 'familia']);
        $g2 = $svc->createGroup($admin, ['name' => 'B', 'type' => 'familia']);
        $m1 = $svc->addMember($admin, $g1, 'Polly', 'polly@example.com', null);
        $m2 = $svc->addMember($admin, $g2, 'Polly', 'POLLY@example.com', null);
        $m3 = $svc->addMember($admin, $g2, 'Outra pessoa', 'outra2@example.com', null);

        // Mudança feita pela tela de Contatos (form).
        Volt::test('contacts.form', ['contact' => $c->fresh()])->set('name', 'Pollyana do Nascimento')->call('save');
        $this->assertSame('Pollyana do Nascimento', $c->fresh()->name);
        $this->assertSame('Pollyana do Nascimento', $m1->fresh()->name);
        $this->assertSame('Pollyana do Nascimento', $m2->fresh()->name);
        $this->assertSame('Pollyana do Nascimento', $u->fresh()->name);
        $this->assertSame('Outra pessoa', $m3->fresh()->name, 'Sem vínculo, não muda.');
        $this->assertSame('Cassio', $admin->fresh()->name);
        $this->assertSame('Cassio', $g1->fresh()->ownerMember()->name);

        // Outro contato sem vínculo não afeta ninguém.
        $other->update(['name' => 'Outra Renomeada']);
        $this->assertSame('Outra pessoa', $m3->fresh()->name);

        // Caminho inverso continua: "Editar nome" (admin) atualiza grupos, usuário e contato.
        Volt::test('divisions.show', ['group' => $g1->fresh()])->call('editName', $m1->id)->set('memberNameDraft', 'Pollyana N.')->call('saveName')->assertHasNoErrors();
        $this->assertSame(2, DivisionMember::where('name', 'Pollyana N.')->count());
        $this->assertSame('Pollyana N.', $c->fresh()->name);
        $this->assertSame('Pollyana N.', $u->fresh()->name);
    }

    public function test_contact_email_link_without_user(): void
    {
        $admin = User::factory()->create(['name' => 'Cassio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($admin);
        $c = Contact::create(['name' => 'Ana', 'email' => 'ana@example.com', 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $svc = app(DivisionService::class);
        $g = $svc->createGroup($admin, ['name' => 'A', 'type' => 'familia']);
        $m = $svc->addMember($admin, $g, 'Ana', 'ana@example.com', null);

        $c->update(['name' => 'Ana Souza']);
        $this->assertSame('Ana Souza', $m->fresh()->name);
    }
}

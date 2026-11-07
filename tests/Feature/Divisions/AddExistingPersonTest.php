<?php

namespace Tests\Feature\Divisions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AddExistingPersonTest extends TestCase
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

    public function test_search_and_add_existing_account_without_invite_and_with_phone(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $shc = $this->svc()->createGroup($admin, ['name' => 'SHC - 3 irmãos', 'type' => 'familia']);
        $cdl = $this->svc()->createGroup($admin, ['name' => 'CDL', 'type' => 'familia']);
        $c = Contact::create(['name' => 'Heleno Mota', 'email' => 'heleno@example.com', 'phone' => '(99) 98802-5000', 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $heleno = User::factory()->create(['name' => 'Heleno Mota', 'email' => 'heleno@example.com']);
        $heleno->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes'], 'contact_id' => $c->id])->save();
        $sandro = User::factory()->create(['name' => 'Sandro Mota', 'email' => 'sandro@example.com']);
        $sandro->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['financeiro']])->save();
        $this->svc()->addMember($admin, $shc, 'Sandro Mota', 'sandro@example.com', '(99) 98802-6000');

        $page = Volt::test('divisions.show', ['group' => $cdl])->assertSeeHtml('data-add-existing')->assertSee('Adicionar novo participante')
            ->set('personSearch', 'mota')->assertSee('Heleno Mota')->assertSee('Sandro Mota')->assertSee('Já tem conta');
        $found = collect($this->svc()->searchExistingPeople($cdl, 'mota'));
        $this->assertCount(2, $found, 'uma linha por pessoa (sem duplicar usuário/participante/contato)');
        $this->assertNotEmpty(collect($this->svc()->searchExistingPeople($cdl, '98802-50')));

        $page->call('addExisting', $found->firstWhere('email', 'heleno@example.com')['key'])->assertHasNoErrors();
        $page->call('addExisting', $found->firstWhere('email', 'sandro@example.com')['key'])->assertHasNoErrors();
        $h = $cdl->members()->where('email', 'heleno@example.com')->first();
        $s = $cdl->members()->where('email', 'sandro@example.com')->first();
        $this->assertSame($heleno->id, $h->user_id);
        $this->assertSame('+5599988025000', $h->phone);
        $this->assertSame($sandro->id, $s->user_id);
        $this->assertSame('+5599988026000', $s->phone);
        $this->assertContains('divisoes', $sandro->fresh()->modules, 'conta existente recebe o módulo');
        $this->assertContains('financeiro', $sandro->fresh()->modules);
        $this->assertSame(0, UserInvitation::count(), 'nenhum convite novo');

        // Não duplica no mesmo grupo e some da busca.
        $page->call('addExisting', 'u:'.$heleno->id)->assertHasErrors('personSearch');
        $this->assertSame([], $this->svc()->searchExistingPeople($cdl, 'mota'));

        // Terceira pessoa nova continua pelo formulário de novo participante.
        $page->set('memberName', 'Pessoa Nova')->set('memberEmail', 'nova@example.com')->call('addMember')->assertHasNoErrors(['memberName', 'memberEmail', 'memberPhone']);
        $this->assertSame(4, $cdl->members()->count());
    }
}

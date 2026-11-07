<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MemberApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function user(string $name, string $email, bool $admin = false): User
    {
        $u = User::factory()->create(['name' => $name, 'email' => $email]);
        $u->forceFill(['is_admin' => $admin, 'status' => 'active', 'modules' => $admin ? array_keys(\App\Support\Access::MODULES) : ['divisoes']])->save();

        return $u;
    }

    private function setupGroup(): array
    {
        $admin = $this->user('Cassio', 'cassio@example.com', true);
        $mat = $this->user('Matheus', 'matheus@example.com');
        $g = $this->svc()->createGroup($admin, ['name' => 'Terra', 'type' => 'amigos', 'requires_approval' => false]);
        $this->svc()->addMember($admin, $g, 'Matheus', 'matheus@example.com');
        $g->load('members');

        return [$admin, $mat, $g];
    }

    public function test_participant_external_person_waits_for_approval(): void
    {
        [$admin, $mat, $g] = $this->setupGroup();
        $this->actingAs($mat);
        $m = $this->svc()->addMember($mat, $g, 'Pedro', 'pedro@example.com');
        $this->assertTrue($m->isPending());
        $this->assertFalse($m->active);
        $this->assertSame(0, UserInvitation::where('email', 'pedro@example.com')->count());

        Volt::test('divisions.show', ['group' => $g->fresh()])->assertSee('Aguardando aprovação do gestor')->assertSee('Fale com o gestor para aprovar')
            ->assertDontSee('data-member-decide', false);
        // fica fora das divisões: não aparece nas linhas de rateio e não pode ser usado na despesa
        try {
            $this->svc()->saveExpense($mat, $g->fresh(), ['description' => 'X', 'date' => '2026-11-01', 'amount' => '90', 'paid_by_member_id' => $m->id, 'split_mode' => 'equal', 'inputs' => [$m->id => 1]]);
            $this->fail('pendente não entra');
        } catch (ValidationException) {
        }
        try {
            $this->svc()->inviteMember($admin, $m);
            $this->fail('sem convite antes de aprovar');
        } catch (ValidationException) {
        }
        try {
            $this->svc()->decideMember($mat, $m, true);
            $this->fail('só o admin aprova');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }

        $this->actingAs($admin);
        Volt::test('divisions.index')->assertSee('Participante externo: Pedro')->call('decideMember', $m->id, true)->assertSee('/convite/', false);
        $m->refresh();
        $this->assertFalse($m->isPending());
        $this->assertTrue($m->active);
        $this->assertSame(1, UserInvitation::where('email', 'pedro@example.com')->count());
    }

    public function test_reject_removes_and_admin_adds_external_directly(): void
    {
        [$admin, $mat, $g] = $this->setupGroup();
        $this->actingAs($mat);
        $m = $this->svc()->addMember($mat, $g, 'Pedro', 'pedro@example.com');
        $this->actingAs($admin);
        Volt::test('divisions.show', ['group' => $g->fresh()])->call('decideMember', $m->id, false);
        $this->assertNull(DivisionMember::find($m->id));
        $direct = $this->svc()->addMember($admin, $g->fresh(), 'Ana', 'ana@example.com');
        $this->assertFalse($direct->isPending());
    }

    public function test_participant_adds_existing_user_directly_and_cannot_search_contacts(): void
    {
        [$admin, $mat, $g] = $this->setupGroup();
        $jo = User::factory()->create(['name' => 'Joana', 'email' => 'joana@example.com']);
        $jo->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['transmissao']])->save();
        \App\Domains\Contacts\Models\Contact::forceCreate(['name' => 'Joaquim Contato', 'email' => 'joaquim@example.com', 'status' => true]);
        $this->actingAs($mat);
        $found = $this->svc()->searchExistingPeople($g, 'jo', 8, false);
        $this->assertSame(['u:'.$jo->id], array_column($found, 'key'));
        $m = $this->svc()->addExistingPerson($mat, $g, 'u:'.$jo->id);
        $this->assertFalse($m->isPending());
        $this->assertSame($jo->id, $m->user_id);
        $this->assertContains('divisoes', (array) $jo->fresh()->modules);
        $this->expectException(ValidationException::class);
        $this->svc()->addExistingPerson($mat, $g, 'c:1');
    }

    public function test_outsider_cannot_add_people(): void
    {
        [, , $g] = $this->setupGroup();
        $out = $this->user('Fora', 'fora@example.com');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->svc()->addMember($out, $g, 'X', 'x@example.com');
    }
}

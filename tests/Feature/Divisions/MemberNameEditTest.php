<?php

namespace Tests\Feature\Divisions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MemberNameEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_renames_member_everywhere_and_others_cannot(): void
    {
        $admin = User::factory()->create(['name' => 'Cassio']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $c = Contact::create(['name' => 'Marina', 'email' => 'marina@example.com', 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $u = User::factory()->create(['name' => 'Marina', 'email' => 'marina@example.com']);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes'], 'contact_id' => $c->id])->save();
        $this->actingAs($admin);
        $svc = app(DivisionService::class);
        $g1 = $svc->createGroup($admin, ['name' => 'A', 'type' => 'familia']);
        $g2 = $svc->createGroup($admin, ['name' => 'B', 'type' => 'familia']);
        $m1 = $svc->addMember($admin, $g1, 'Marina', 'marina@example.com', null);
        $svc->addMember($admin, $g2, 'Marina', 'marina@example.com', null);
        $me = $g1->fresh()->ownerMember();
        $svc->saveExpense($admin, $g1->fresh(), ['description' => 'Luz', 'category' => 'outros', 'date' => '2026-10-10', 'amount' => '10.00',
            'paid_by_member_id' => $m1->id, 'split_mode' => 'equal', 'inputs' => [$me->id => 1, $m1->id => 1], 'installments' => 1, 'transaction_id' => null, 'notes' => '']);

        Volt::test('divisions.show', ['group' => $g1->fresh()])->assertSeeHtml('data-edit-name')
            ->call('editName', $m1->id)->set('memberNameDraft', ' Marina   Mota ')->call('saveName')->assertHasNoErrors()
            ->assertSee('Marina Mota pagou R$ 10,00')->assertSee('Você deve R$ 5,00 a');
        $this->assertSame(2, \App\Domains\Divisions\Models\DivisionMember::where('name', 'Marina Mota')->count());
        $this->assertSame('Marina Mota', $u->fresh()->name);
        $this->assertSame('Marina Mota', $c->fresh()->name);
        Volt::test('divisions.index')->assertSee('Marina Mota');
        Volt::test('divisions.show', ['group' => $g1->fresh()])->call('editName', $m1->id)->set('memberNameDraft', 'x')->call('saveName')->assertHasErrors('memberNameDraft');

        $this->actingAs($u->fresh());
        Volt::test('divisions.show', ['group' => $g1->fresh()])->assertDontSeeHtml('data-edit-name')->call('editName', $m1->id)->assertForbidden();
    }
}

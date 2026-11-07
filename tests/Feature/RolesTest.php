<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RolePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, bool $admin = false): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => $role, 'is_admin' => $admin, 'modules' => \App\Support\Access::clean(array_keys(\App\Support\Access::MODULES ?? []))])->save();

        return $u->fresh();
    }

    public function test_policy_rules(): void
    {
        $v = $this->user('viewer');
        $o = $this->user('operator');
        $a = $this->user('viewer', true);
        $this->assertNotNull(RolePolicy::blockReason($v, 'banking.transactions.index', 'save'));
        $this->assertNull(RolePolicy::blockReason($v, 'banking.transactions.index', 'gotoPage'));
        $this->assertNull(RolePolicy::blockReason($v, 'writs.kanban', '$set'));
        $this->assertNull(RolePolicy::blockReason($o, 'banking.transactions.index', 'save'));
        $this->assertNull(RolePolicy::blockReason($o, 'banking.transactions.index', 'delete'));
        $this->assertNotNull(RolePolicy::blockReason($o, 'contacts.index', 'delete'));
        $this->assertNull(RolePolicy::blockReason($a, 'contacts.index', 'delete'));
        $this->assertSame('Administrador', RolePolicy::label($a));
    }

    public function test_viewer_blocked_in_livewire_and_http_and_sees_banner(): void
    {
        $admin = $this->user('operator', true);
        $this->actingAs($admin);
        Volt::test('writs.form')->set('process_number', '')->call('save')->assertStatus(200);
        $v = $this->user('viewer');
        $v->forceFill(['modules' => $admin->modules ?? null])->save();
        $this->actingAs($v);
        Volt::test('writs.form')->call('save')->assertStatus(403);
        $this->assertNotEquals(403, $this->post(route('logout'))->getStatusCode());
    }

    public function test_admin_sets_role(): void
    {
        $admin = $this->user('operator', true);
        $u = $this->user('operator');
        $this->actingAs($admin);
        Volt::test('settings.users')->set("editRoles.{$u->id}", 'viewer')->call('saveRole', $u->id)->assertHasNoErrors();
        $this->assertSame('viewer', $u->fresh()->role);
    }
}

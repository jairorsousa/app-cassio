<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AuditLogger::resetCache();
    }

    public function test_create_update_delete_are_logged_with_before_after(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        $c = \App\Domains\Contacts\Models\Contact::forceCreate(['name' => 'Fulano Auditado', 'type' => 'pf']);
        $c->forceFill(['name' => 'Fulano Editado'])->save();
        $c->delete();
        $logs = AuditLog::where('entity_type', 'Contact')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('Fulano Auditado', $logs[1]->before['name']);
        $this->assertSame('Fulano Editado', $logs[1]->after['name']);
        $this->assertSame($admin->id, $logs[1]->user_id);
        $u = AuditLog::where('entity_type', 'User')->where('action', 'updated')->first();
        $this->assertNotNull($u);
        $admin->forceFill(['password' => bcrypt('x')])->save();
        $this->assertSame('(oculto)', AuditLog::where('entity_type', 'User')->latest('id')->first()->after['password']);
    }

    public function test_screen_admin_only_with_filters(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $other = User::factory()->create();
        $this->actingAs($admin);
        \App\Domains\Contacts\Models\Contact::forceCreate(['name' => 'Beltrano Log', 'type' => 'pf']);
        $this->get(route('settings.audit'))->assertOk()->assertSee('Log de auditoria')->assertSee('Beltrano Log');
        Volt::test('settings.audit')->set('entity', 'Contact')->assertSee('Beltrano Log')->set('action', 'deleted')->assertDontSee('Beltrano Log');
        $this->actingAs($other);
        $this->get(route('settings.audit'))->assertForbidden();
    }
}

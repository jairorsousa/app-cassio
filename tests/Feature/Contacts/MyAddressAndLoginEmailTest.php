<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\IdentityChangeRequest;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\GoogleAuth\IdentityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Meus dados (endereço e Pix direto, com auditoria) e troca do e-mail de acesso só pelo admin. */
class MyAddressAndLoginEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Cassio', 'email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $admin;
    }

    /** @return array{0: Contact, 1: User} */
    private function person(): array
    {
        $c = Contact::create(['name' => 'Polly', 'document' => '529.982.247-25', 'email' => 'polly@example.com', 'emails' => ['polly@example.com'], 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $u = User::factory()->create(['name' => 'Polly', 'email' => 'polly@example.com']);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes'], 'contact_id' => $c->id, 'profile_completed_at' => now(), 'google_sub' => 'sub-antigo'])->save();

        return [$c, $u];
    }

    public function test_person_edits_own_address_and_pix_directly_with_audit(): void
    {
        [$c, $u] = $this->person();
        $this->actingAs($u);

        Volt::test('profile.identity')
            ->set('zip_code', '60115170')->set('street', 'Rua das Flores')->set('number', '10')->set('neighborhood', 'Aldeota')
            ->set('city', 'Fortaleza')->set('state', 'ce')->set('pix_key_type', 'email')->set('pix_key', 'polly@example.com')
            ->call('saveAddress')->assertHasNoErrors()->assertSet('addressMessage', 'Endereço e Pix salvos.')->assertSet('zip_code', '60115-170');

        $c->refresh();
        $this->assertSame(['60115-170', 'Rua das Flores', '10', 'Aldeota', 'Fortaleza', 'CE', 'email', 'polly@example.com'], [$c->zip_code, $c->street, $c->number, $c->neighborhood, $c->city, $c->state, $c->pix_key_type, $c->pix_key]);
        $this->assertSame('Rua das Flores, 10 | Aldeota - Fortaleza - CE - 60115-170', $c->address);
        $this->assertSame(0, IdentityChangeRequest::count(), 'Sem aprovação.');
        $log = AuditLog::where('entity_type', 'Contact')->where('entity_id', $c->id)->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($u->id, $log->user_id);
        $this->assertSame('Rua das Flores', $log->after['street']);
        $this->assertSame('polly@example.com', $log->after['pix_key']);

        // Validações (CEP, UF e Pix que não confere com o tipo).
        Volt::test('profile.identity')->set('zip_code', '123')->call('saveAddress')->assertHasErrors('zip_code');
        Volt::test('profile.identity')->set('state', 'Ceará')->call('saveAddress')->assertHasErrors('state');
        Volt::test('profile.identity')->set('pix_key_type', 'cpf')->set('pix_key', '111.111.111-11')->call('saveAddress')->assertHasErrors('pix_key');
        Volt::test('profile.identity')->set('pix_key_type', '')->set('pix_key', 'abc')->call('saveAddress')->assertHasErrors('pix_key');

        // Endereço manual sem CEP também vale.
        Volt::test('profile.identity')->set('zip_code', '')->set('street', 'Sítio Boa Vista')->set('city', 'Quixadá')->set('state', 'CE')->set('pix_key_type', '')->set('pix_key', '')
            ->call('saveAddress')->assertHasNoErrors();
        $this->assertNull($c->fresh()->zip_code);
        $this->assertNull($c->fresh()->pix_key);
        $this->get('/profile')->assertOk()->assertSee('data-my-address', false)->assertSee('Salvar endereço e Pix');
    }

    public function test_admin_changes_login_email_with_confirmation_and_audit(): void
    {
        Mail::fake();
        Notification::fake();
        $admin = $this->admin();
        [$c, $u] = $this->person();
        Contact::create(['name' => 'Outra', 'email' => 'outra@example.com', 'emails' => ['outra@example.com'], 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $this->actingAs($admin);
        $g = app(DivisionService::class)->createGroup($admin, ['name' => 'A', 'type' => 'familia']);
        $m = app(DivisionService::class)->addMember($admin, $g, 'Polly', 'polly@example.com', null);
        $m->forceFill(['user_id' => $u->id])->save();

        $this->get(route('contacts.show', $c))->assertOk()->assertSee('Acesso ao sistema')->assertSee('Trocar e-mail de acesso');
        $this->get(route('settings.users'))->assertSee('data-user-login-link', false);

        $cmp = Volt::test('contacts.login-email', ['contact' => $c])->call('start', $u->id);
        $cmp->set('newLoginEmail', 'outra@example.com')->call('review')->assertHasErrors('newLoginEmail')->assertSet('confirming', false);
        $cmp->set('newLoginEmail', 'admin@example.com')->call('review')->assertHasErrors('newLoginEmail');
        $cmp->set('newLoginEmail', 'Polly.Nova@Gmail.com')->call('review')->assertHasNoErrors()->assertSet('confirming', true)->assertSee('Confirmar a troca?');
        $this->assertSame('polly@example.com', $u->fresh()->email, 'Nada muda antes de confirmar.');
        $cmp->call('confirm')->assertHasNoErrors();

        $u->refresh();
        $this->assertSame('polly.nova@gmail.com', $u->email);
        $this->assertNull($u->google_sub);
        $this->assertSame(['polly.nova@gmail.com', 'polly@example.com'], $c->fresh()->emails, 'O antigo continua na lista.');
        $this->assertSame('polly.nova@gmail.com', $m->fresh()->email);
        $log = AuditLog::where('action', 'login_email_changed')->sole();
        $this->assertSame([$admin->id, $u->id, 'polly@example.com', 'polly.nova@gmail.com'], [$log->user_id, $log->entity_id, $log->before['email'], $log->after['email']]);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();

        // Login com senha usa o novo e-mail.
        auth()->logout();
        $this->assertTrue(auth()->attempt(['email' => 'polly.nova@gmail.com', 'password' => 'password']));
        auth()->logout();

        // Login com Google na conta nova funciona (vínculo antigo foi zerado).
        $fake = new class implements IdentityProvider {
            public function configured(): bool { return true; }
            public function authorizationUrl(string $state, string $codeChallenge): string { return 'https://accounts.example.test/auth?state='.$state; }
            public function verifiedIdentity(string $code, string $codeVerifier): ?array { return ['sub' => 'sub-novo', 'email' => 'polly.nova@gmail.com', 'email_verified' => true]; }
        };
        $this->app->instance(IdentityProvider::class, $fake);
        $r = $this->get('/login/google');
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->get('/login/google/callback?state='.$q['state'].'&code=abc');
        $this->assertAuthenticatedAs($u->fresh());
        $this->assertSame('sub-novo', $u->fresh()->google_sub);
    }

    public function test_only_admin_changes_login_email(): void
    {
        $this->admin();
        [$c, $u] = $this->person();
        $staff = User::factory()->create(['email' => 'equipe@example.com']);
        $staff->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['contatos'], 'profile_completed_at' => now()])->save();
        $this->actingAs($staff);
        $this->get(route('contacts.show', $c))->assertOk()->assertDontSee('Trocar e-mail de acesso');
        Volt::test('contacts.login-email', ['contact' => $c])->call('start', $u->id)->assertForbidden();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(\App\Domains\Contacts\Services\PersonIdentityService::class)->changeLoginEmail($u, 'x@example.com', $staff);
    }
}

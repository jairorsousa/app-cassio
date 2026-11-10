<?php

namespace Tests\Feature\Contacts;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\IdentityChangeRequest;
use App\Domains\Contacts\Services\PersonIdentityService;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Identidade da pessoa: nome igual em todos os módulos; CPF único que só muda com aprovação; exclusão de e-mail com aprovação. */
class PersonIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const CPF_A = '529.982.247-25';

    private const CPF_B = '111.444.777-35';

    private const CPF_C = '390.533.447-05';

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Cassio', 'email' => 'admin@example.com']);
        $admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $admin;
    }

    /** @return array{0: Contact, 1: User} */
    private function person(array $modules = ['divisoes']): array
    {
        $c = Contact::create(['name' => 'Polly', 'document' => self::CPF_A, 'email' => 'polly@example.com', 'emails' => ['polly@example.com', 'polly.extra@example.com'], 'roles' => ['pessoal', 'corretor'], 'type' => 'pessoal', 'status' => true, 'pix_key' => 'x']);
        $u = User::factory()->create(['name' => 'Polly', 'email' => 'polly@example.com']);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => $modules, 'contact_id' => $c->id, 'profile_completed_at' => now()])->save();

        return [$c, $u];
    }

    public function test_name_and_cpf_follow_the_contact_in_every_module(): void
    {
        $admin = $this->admin();
        [$c, $u] = $this->person();
        $this->actingAs($admin);
        $broker = Broker::create(['name' => 'Polly', 'document' => self::CPF_A, 'contact_id' => $c->id, 'status' => true]);
        $other = Broker::create(['name' => 'Outro', 'status' => true]);
        $g = app(DivisionService::class)->createGroup($admin, ['name' => 'A', 'type' => 'familia']);
        $m = app(DivisionService::class)->addMember($admin, $g, 'Polly', 'polly@example.com', null);
        DB::statement('PRAGMA defer_foreign_keys = ON'); // linhas mínimas; o teste é desfeito no fim
        $caseType = DB::table('case_types')->insertGetId(['name' => 'Tipo', 'created_at' => now(), 'updated_at' => now()]);
        $commission = DB::table('broker_commissions')->insertGetId(['broker_id' => $broker->id, 'case_type_id' => $caseType, 'base_amount' => 1, 'percentage_applied' => 1, 'commission_amount' => 1, 'reference_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $destClient = DB::table('receipt_destinations')->insertGetId(['receipt_id' => 1, 'kind' => 'client', 'beneficiary' => 'Polly', 'amount' => 10, 'contact_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
        $destBroker = DB::table('receipt_destinations')->insertGetId(['receipt_id' => 1, 'kind' => 'broker', 'beneficiary' => 'Polly', 'amount' => 5, 'commission_id' => $commission, 'created_at' => now(), 'updated_at' => now()]);
        $destOther = DB::table('receipt_destinations')->insertGetId(['receipt_id' => 1, 'kind' => 'client', 'beneficiary' => 'Polly', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_invitations')->insert(['name' => 'Polly', 'email' => 'polly@example.com', 'modules' => '[]', 'token_hash' => 'x', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        // Edição pelo próprio contato (Contatos, admin): nome e CPF valem direto.
        Volt::test('contacts.form', ['contact' => $c->fresh()])->set('name', 'Pollyana Mignoni')->set('document', self::CPF_B)->call('save')->assertHasNoErrors();

        $this->assertSame('Pollyana Mignoni', $u->fresh()->name);
        $this->assertSame('Pollyana Mignoni', $m->fresh()->name);
        $this->assertSame('Pollyana Mignoni', $broker->fresh()->name);
        $this->assertSame(self::CPF_B, $broker->fresh()->document);
        $this->assertSame('Pollyana Mignoni', DB::table('receipt_destinations')->find($destClient)->beneficiary);
        $this->assertSame('Pollyana Mignoni', DB::table('receipt_destinations')->find($destBroker)->beneficiary);
        $this->assertSame('Polly', DB::table('receipt_destinations')->find($destOther)->beneficiary, 'Sem vínculo, não muda.');
        $this->assertSame('Pollyana Mignoni', DB::table('user_invitations')->value('name'));
        $this->assertSame('Outro', $other->fresh()->name);
        $this->assertSame(0, IdentityChangeRequest::count(), 'Admin muda direto, sem pedido.');

        // Caminho inverso (Divisões › Editar nome) também chega ao corretor.
        Volt::test('divisions.show', ['group' => $g->fresh()])->call('editName', $m->id)->set('memberNameDraft', 'Pollyana N. Mignoni')->call('saveName')->assertHasNoErrors();
        $this->assertSame('Pollyana N. Mignoni', $broker->fresh()->name);
    }

    public function test_person_changes_own_data_and_cpf_change_waits_for_admin(): void
    {
        $admin = $this->admin();
        [$c, $u] = $this->person();
        $this->actingAs($u);
        $this->get('/profile')->assertOk()->assertSeeVolt('profile.identity')->assertDontSeeVolt('profile.update-profile-information-form');

        Volt::test('profile.identity')->set('name', 'Pollyana  do Nascimento')->set('phone', '(85) 99999-1234')->call('saveData')->assertHasNoErrors();
        $this->assertSame('Pollyana do Nascimento', $c->fresh()->name);
        $this->assertSame('Pollyana do Nascimento', $u->fresh()->name);

        Volt::test('profile.identity')->set('newDocument', self::CPF_B)->call('requestDocument')->assertHasNoErrors();
        $this->assertSame(self::CPF_A, $c->fresh()->document, 'CPF não muda antes da aprovação.');
        $req = IdentityChangeRequest::sole();
        $this->assertSame(['document', 'pending', self::CPF_A, self::CPF_B, $u->id], [$req->type, $req->status, $req->old_value, $req->new_value, $req->requested_by]);

        // Novo pedido substitui o pendente (um só por pessoa).
        Volt::test('profile.identity')->set('newDocument', self::CPF_C)->call('requestDocument');
        $this->assertSame(1, IdentityChangeRequest::count());
        $this->assertSame(self::CPF_C, $req->fresh()->new_value);

        // Não-admin não decide.
        $this->get(route('settings.identity'))->assertForbidden();
        $this->expectsNoApproval(fn () => app(PersonIdentityService::class)->approve($req, $u));

        // Admin vê o aviso e aprova.
        $this->actingAs($admin);
        $this->get(route('settings.identity'))->assertOk()->assertSee('Pendências de cadastro')->assertSee(self::CPF_C)->assertSee('Pollyana do Nascimento');
        $this->get(route('settings'))->assertSee('data-identity-bell', false)->assertSee('data-identity-count', false);
        Volt::test('settings.identity-requests')->call('approve', $req->id)->assertSet('errorMessage', '');
        $this->assertSame(self::CPF_C, $c->fresh()->document);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSame($admin->id, $req->fresh()->decided_by);
        $this->get(route('settings'))->assertDontSee('data-identity-bell', false);
    }

    public function test_reject_keeps_cpf_and_cpf_is_unique(): void
    {
        $admin = $this->admin();
        [$c, $u] = $this->person();
        Contact::create(['name' => 'Outra', 'document' => self::CPF_B, 'roles' => ['cedente'], 'type' => 'cedente', 'status' => true]);
        $this->actingAs($u);
        Volt::test('profile.identity')->set('newDocument', self::CPF_B)->call('requestDocument')->assertHasErrors('newDocument');
        Volt::test('profile.identity')->set('newDocument', '123.456.789-00')->call('requestDocument')->assertHasErrors('newDocument');
        $this->assertSame(0, IdentityChangeRequest::count());
        Volt::test('profile.identity')->set('newDocument', self::CPF_C)->call('requestDocument');

        $this->actingAs($admin);
        $req = IdentityChangeRequest::sole();
        Volt::test('settings.identity-requests')->call('startReject', $req->id)->set('rejectNote', 'CPF de outra pessoa')->call('confirmReject');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame('CPF de outra pessoa', $req->fresh()->decision_note);
        $this->assertSame(self::CPF_A, $c->fresh()->document);

        // Contatos também barra CPF repetido (inclusive para o admin).
        Volt::test('contacts.form', ['contact' => $c->fresh()])->set('document', self::CPF_B)->call('save')->assertHasErrors('document');
        Volt::test('contacts.index')->call('create')->set('name', 'Nova')->set('roles', ['cedente'])->set('type_form', 'cedente')->set('document', self::CPF_A)->call('save')->assertHasErrors('document');
    }

    public function test_emails_add_freely_and_removal_waits_for_admin(): void
    {
        $admin = $this->admin();
        [$c, $u] = $this->person();
        Contact::create(['name' => 'Outra', 'email' => 'outra@example.com', 'emails' => ['outra@example.com'], 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $this->actingAs($u);

        Volt::test('profile.identity')->set('newEmail', 'Polly.Novo@Example.com')->call('addEmail')->assertHasNoErrors();
        $this->assertSame(['polly@example.com', 'polly.extra@example.com', 'polly.novo@example.com'], $c->fresh()->emails);
        Volt::test('profile.identity')->set('newEmail', 'outra@example.com')->call('addEmail')->assertHasErrors('newEmail');
        Volt::test('profile.identity')->set('newEmail', 'admin@example.com')->call('addEmail')->assertHasNoErrors();

        // E-mail de acesso não pode ser excluído por aqui.
        Volt::test('profile.identity')->call('removeEmail', 'polly@example.com')->assertHasErrors('emails');
        $this->assertSame(0, IdentityChangeRequest::count());

        Volt::test('profile.identity')->call('removeEmail', 'polly.extra@example.com')->assertHasNoErrors()->assertSee('Exclusão aguardando aprovação');
        Volt::test('profile.identity')->call('removeEmail', 'polly.extra@example.com');
        $req = IdentityChangeRequest::sole();
        $this->assertContains('polly.extra@example.com', $c->fresh()->emails, 'Continua até aprovar.');

        $this->actingAs($admin);
        Volt::test('settings.identity-requests')->call('approve', $req->id);
        $this->assertNotContains('polly.extra@example.com', $c->fresh()->emails);
        $this->assertSame('polly@example.com', $c->fresh()->email);
    }

    public function test_person_can_cancel_own_request(): void
    {
        [$c, $u] = $this->person();
        $this->actingAs($u);
        Volt::test('profile.identity')->call('removeEmail', 'polly.extra@example.com');
        $req = IdentityChangeRequest::sole();
        Volt::test('profile.identity')->call('cancelRequest', $req->id);
        $this->assertSame('cancelled', $req->fresh()->status);
        $this->assertContains('polly.extra@example.com', $c->fresh()->emails);
    }

    public function test_non_admin_editing_in_contacts_creates_requests_admin_applies_directly(): void
    {
        $admin = $this->admin();
        [$c] = $this->person();
        $staff = User::factory()->create(['name' => 'Equipe', 'email' => 'equipe@example.com']);
        $staff->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['contatos'], 'profile_completed_at' => now()])->save();

        $this->actingAs($staff);
        Volt::test('contacts.form', ['contact' => $c->fresh()])
            ->set('name', 'Polly Editada')->set('document', self::CPF_B)->set('emails', ['polly@example.com'])->call('save')->assertHasNoErrors();
        $c->refresh();
        $this->assertSame('Polly Editada', $c->name, 'Nome muda direto.');
        $this->assertSame(self::CPF_A, $c->document, 'CPF fica pendente.');
        $this->assertSame(['polly@example.com', 'polly.extra@example.com'], $c->emails, 'E-mail fica até aprovar.');
        $this->assertEqualsCanonicalizing(['document', 'email_remove'], IdentityChangeRequest::pluck('type')->all());

        // Modal da lista de Contatos segue a mesma regra.
        Volt::test('contacts.index')->call('edit', $c->id)->set('document', self::CPF_C)->call('save')->assertHasNoErrors();
        $this->assertSame(self::CPF_A, $c->fresh()->document);
        $this->assertSame(self::CPF_C, IdentityChangeRequest::where('type', 'document')->sole()->new_value);

        $this->actingAs($admin);
        Volt::test('contacts.form', ['contact' => $c->fresh()])->set('document', self::CPF_B)->set('emails', ['polly@example.com'])->call('save')->assertHasNoErrors();
        $this->assertSame(self::CPF_B, $c->fresh()->document);
        $this->assertSame(['polly@example.com'], $c->fresh()->emails);
    }

    public function test_profile_completion_does_not_overwrite_existing_cpf(): void
    {
        $this->admin();
        $c = Contact::create(['name' => 'Ana', 'document' => self::CPF_A, 'email' => 'ana@example.com', 'emails' => ['ana@example.com'], 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $u = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        app(\App\Support\ProfileCompletionService::class)->complete($u, [
            'name' => 'Ana Souza', 'document' => self::CPF_B, 'phone' => '(85) 99999-0000', 'birth_date' => '1990-01-01',
            'zip_code' => '60000-000', 'street' => 'Rua', 'number' => '1', 'neighborhood' => 'B', 'city' => 'Fortaleza', 'state' => 'CE',
        ]);
        $this->assertSame(self::CPF_A, $c->fresh()->document);
        $this->assertSame('Ana Souza', $c->fresh()->name);
        $this->assertSame(self::CPF_B, IdentityChangeRequest::sole()->new_value);
    }

    private function expectsNoApproval(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Não-admin não pode aprovar.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
    }
}

<?php

namespace Tests\Feature\Divisions;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneFromContactTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function userWithContact(string $email, ?string $phone): User
    {
        $c = Contact::create(['name' => 'Heleno', 'email' => $email, 'phone' => $phone, 'phones' => $phone ? [$phone] : [], 'roles' => ['pessoal'], 'type' => 'pessoal', 'status' => true]);
        $u = User::factory()->create(['email' => $email]);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes'], 'contact_id' => $c->id])->save();

        return $u;
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    public function test_add_member_uses_contact_phone_but_keeps_manual(): void
    {
        $admin = $this->admin();
        $this->userWithContact('heleno@example.com', '(85) 99999-8888');
        $g = $this->svc()->createGroup($admin, ['name' => 'SHC', 'type' => 'familia']);
        $m = $this->svc()->addMember($admin, $g, 'Heleno', 'heleno@example.com');
        $this->assertSame('+5585999998888', $m->phone);

        $g2 = $this->svc()->createGroup($admin, ['name' => 'Outro', 'type' => 'familia']);
        $m2 = $this->svc()->addMember($admin, $g2, 'Heleno', 'heleno@example.com', '(11) 98888-7777');
        $this->assertSame('+5511988887777', $m2->fresh()->phone);
    }

    public function test_claim_and_backfill_fill_only_empty_phones(): void
    {
        $admin = $this->admin();
        $g = $this->svc()->createGroup($admin, ['name' => 'SHC', 'type' => 'familia']);
        $empty = $this->svc()->addMember($admin, $g, 'Heleno', 'heleno@example.com');
        $manual = $this->svc()->addMember($admin, $g, 'Sandro', 'sandro@example.com', '(11) 98888-7777');
        $noContact = $this->svc()->addMember($admin, $g, 'Ana', 'ana@example.com');
        $h = $this->userWithContact('heleno@example.com', '(85) 99999-8888');
        $s = $this->userWithContact('sandro@example.com', '(85) 91111-2222');
        $this->svc()->claimMemberships($h);
        $this->assertSame('+5585999998888', $empty->fresh()->phone);

        DivisionMember::whereKey($manual->id)->update(['user_id' => $s->id]);
        $a = User::factory()->create(['email' => 'ana@example.com']);
        DivisionMember::whereKey($noContact->id)->update(['user_id' => $a->id]);
        $this->artisan('divisions:fill-phones')->assertSuccessful();
        $this->assertSame('+5511988887777', $manual->fresh()->phone, 'manual não é sobrescrito');
        $this->assertNull($noContact->fresh()->phone);
    }

    public function test_profile_completion_does_not_overwrite_manual_phone(): void
    {
        $admin = $this->admin();
        $g = $this->svc()->createGroup($admin, ['name' => 'SHC', 'type' => 'familia']);
        $manual = $this->svc()->addMember($admin, $g, 'Heleno', 'heleno@example.com', '(11) 98888-7777');
        $g2 = $this->svc()->createGroup($admin, ['name' => 'Outro', 'type' => 'familia']);
        $empty = $this->svc()->addMember($admin, $g2, 'Heleno', 'heleno@example.com');
        $u = User::factory()->create(['email' => 'heleno@example.com', 'profile_completed_at' => null]);
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        app(\App\Support\ProfileCompletionService::class)->complete($u, ['name' => 'Heleno Mota', 'phone' => '(85) 99999-8888', 'document' => '52998224725', 'birth_date' => '1980-01-01',
            'zip_code' => '60000000', 'street' => 'Rua', 'number' => '1', 'neighborhood' => 'B', 'city' => 'Fortaleza', 'state' => 'CE']);
        $this->assertSame('+5511988887777', $manual->fresh()->phone);
        $this->assertSame('+5585999998888', $empty->fresh()->phone);
        $this->assertSame($u->id, $empty->fresh()->user_id);
    }
}

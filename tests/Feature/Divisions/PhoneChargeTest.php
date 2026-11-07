<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Models\DivisionAudit;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PhoneChargeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['name' => 'Cassio Mota']);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function setup2(?string $phone = '(85) 99999-8888'): array
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos', 'requires_approval' => false]);
        $b = $this->svc()->addMember($admin, $g, 'Matheus Silva', 'matheus@example.com', $phone);
        $g->load('members');
        $a = $g->ownerMember();
        $this->svc()->saveExpense($admin, $g, ['description' => 'Hotel', 'date' => '2026-11-01', 'amount' => '200', 'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1]]);

        return [$admin, $g->fresh(), $a, $b];
    }

    public function test_phone_normalization_and_validation(): void
    {
        $s = $this->svc();
        $this->assertSame('+5585999998888', $s->normalizePhone('(85) 99999-8888'));
        $this->assertSame('+5585999998888', $s->normalizePhone('+55 85 99999-8888'));
        $this->assertSame('+558532223333', $s->normalizePhone('8532223333'));
        $this->assertNull($s->normalizePhone(''));
        $this->expectException(ValidationException::class);
        $s->normalizePhone('9999');
    }

    public function test_add_member_with_phone_and_invalid_phone(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $this->assertSame('+5585999998888', $b->fresh()->phone);
        $this->assertSame('(85) 99999-8888', $b->fresh()->phoneDisplay());
        Volt::test('divisions.show', ['group' => $g])->set('memberName', 'Ana')->set('memberPhone', '123')->call('addMember')->assertHasErrors('memberPhone');
        $this->assertFalse(DivisionMember::where('name', 'Ana')->exists());
    }

    public function test_participant_edits_only_own_phone(): void
    {
        [$admin, $g, $a, $b] = $this->setup2(null);
        $p = User::factory()->create(['email' => 'matheus@example.com']);
        $p->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $b->update(['user_id' => $p->id]);
        $this->actingAs($p);
        Volt::test('divisions.show', ['group' => $g])->call('editPhone', $b->id)->set('phoneDraft', '(11) 98888-7777')->call('savePhone')->assertHasNoErrors();
        $this->assertSame('+5511988887777', $b->fresh()->phone);
        Volt::test('divisions.show', ['group' => $g])->call('editPhone', $a->id)->assertForbidden();
    }

    public function test_charge_button_preview_and_audit(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $page = Volt::test('divisions.show', ['group' => $g])->assertSeeHtml('data-charge')->call('openCharge', $b->id, 10000)
            ->assertSeeHtml('data-charge-modal')->assertSee('Cobrar Matheus Silva');
        $text = $page->get('chargeText');
        $this->assertStringContainsString('Olá, Matheus!', $text);
        $this->assertStringContainsString('R$ 100,00', $text);
        $this->assertStringContainsString('"Viagem"', $text);
        $this->assertStringContainsString(route('divisions.show', $g), $text);
        $page->assertSeeHtml('https://wa.me/5585999998888?text=');
        $this->assertSame(0, DivisionAudit::where('action', 'charge_opened')->count());
        $page->call('logCharge')->assertSet('chargeMemberId', null)->assertSee('Cobrança aberta');
        $audit = DivisionAudit::where('action', 'charge_opened')->first();
        $this->assertSame('R$ 100,00', $audit->details['valor']);
        $this->assertNotEmpty($audit->details['data']);
    }

    public function test_charge_disabled_without_phone_and_overall(): void
    {
        [$admin, $g, $a, $b] = $this->setup2(null);
        Volt::test('divisions.show', ['group' => $g])->assertSeeHtml('title="Cadastre o celular"')->assertDontSeeHtml('data-charge-modal');
        Volt::test('divisions.index')->assertSeeHtml('data-charge-disabled');
        $b->update(['phone' => '+5585999998888']);
        $key = $b->fresh()->personKey();
        $idx = Volt::test('divisions.index')->assertSeeHtml('wire:click="openCharge(')->call('openCharge', $key)->assertSeeHtml('data-charge-modal');
        $this->assertStringContainsString('R$ 100,00', $idx->get('chargeText'));
        $idx->call('logCharge');
        $this->assertSame(1, DivisionAudit::where('action', 'charge_opened')->count());
    }

    public function test_participant_cannot_charge(): void
    {
        [$admin, $g, $a, $b] = $this->setup2();
        $p = User::factory()->create(['email' => 'matheus@example.com']);
        $p->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $this->actingAs($p);
        Volt::test('divisions.show', ['group' => $g])->assertDontSeeHtml('data-charge')->call('openCharge', $b->id, 100)->assertForbidden();
    }

    public function test_settings_saves_phone_for_person(): void
    {
        [$admin, $g, $a, $b] = $this->setup2(null);
        Volt::test('settings.users')->assertSeeHtml('data-div-phone')
            ->set('divPhone.'.md5('matheus@example.com'), '(85) 98765-4321')->call('divSavePhone', 'matheus@example.com')->assertHasNoErrors();
        $this->assertSame('+5585987654321', $b->fresh()->phone);
        Volt::test('settings.users')->set('divPhone.'.md5('matheus@example.com'), '12')->call('divSavePhone', 'matheus@example.com')->assertHasErrors('divisions');
    }
}

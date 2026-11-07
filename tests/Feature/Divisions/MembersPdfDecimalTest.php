<?php

namespace Tests\Feature\Divisions;

use App\Domains\Divisions\Models\DivisionAudit;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MembersPdfDecimalTest extends TestCase
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

    private function setup3(): array
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $g = $this->svc()->createGroup($admin, ['name' => 'Viagem', 'type' => 'amigos', 'requires_approval' => false]);
        $b = $this->svc()->addMember($admin, $g, 'Matheus', 'matheus@example.com');
        $c = $this->svc()->addMember($admin, $g, 'Ana', 'ana@example.com');
        $g->load('members');

        return [$admin, $g, $g->ownerMember(), $b, $c];
    }

    public function test_remove_member_without_history_from_group_screen(): void
    {
        [$admin, $g, $a, $b, $c] = $this->setup3();
        Volt::test('divisions.show', ['group' => $g])->assertSeeHtml('data-remove-member')->call('removeMember', $c->id)->assertSet('blockedMemberId', null);
        $this->assertNull(DivisionMember::find($c->id));
        $this->assertTrue(DivisionAudit::where('action', 'member_removed')->exists());
    }

    public function test_member_with_history_shows_reason_and_can_be_deactivated(): void
    {
        [$admin, $g, $a, $b, $c] = $this->setup3();
        $this->svc()->saveExpense($admin, $g, ['description' => 'Gás', 'date' => '2026-11-01', 'amount' => '90', 'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1, $c->id => 1]]);
        $page = Volt::test('divisions.show', ['group' => $g])->call('removeMember', $c->id)
            ->assertSet('blockedMemberId', $c->id)->assertSee('Ana tem parte em despesas')->assertSeeHtml('data-deactivate-member');
        $this->assertNotNull(DivisionMember::find($c->id));
        $page->call('setMemberActive', $c->id, false)->assertSeeHtml('data-member-inactive')->assertSeeHtml('data-reactivate-member');
        $this->assertFalse($c->fresh()->active);
        $this->assertTrue(DivisionAudit::where('action', 'member_deactivated')->exists());

        // Saldo e histórico continuam; nova despesa não aceita desativado.
        $this->assertSame(-3000, $this->svc()->netBalances($g->fresh())[$c->id]);
        $page->call('newExpense')->assertSet('splitRows', [$a->id => ['on' => true, 'value' => ''], $b->id => ['on' => true, 'value' => '']]);
        try {
            $this->svc()->saveExpense($admin, $g->fresh(), ['description' => 'X', 'date' => '2026-11-02', 'amount' => '10', 'paid_by_member_id' => $c->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1]]);
            $this->fail('deveria recusar pagador desativado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('paidBy', $e->errors());
        }
        $e = $this->svc()->saveExpense($admin, $g->fresh(), ['description' => 'Y', 'date' => '2026-11-02', 'amount' => '10', 'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $c->id => 1]])->first();
        $this->assertSame([$a->id], $e->shares()->pluck('member_id')->all());

        $page->call('setMemberActive', $c->id, true);
        $this->assertTrue($c->fresh()->active);
    }

    public function test_participant_cannot_deactivate_or_remove(): void
    {
        [$admin, $g, $a, $b] = $this->setup3();
        $this->expectException(\Throwable::class);
        $this->svc()->setMemberActive(User::factory()->create(), $b, false);
    }

    public function test_pdf_statement_with_period_and_access(): void
    {
        [$admin, $g, $a, $b, $c] = $this->setup3();
        $this->svc()->saveExpense($admin, $g, ['description' => 'Antiga', 'date' => '2026-10-01', 'amount' => '30', 'paid_by_member_id' => $a->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1, $c->id => 1]]);
        $this->svc()->saveExpense($admin, $g, ['description' => 'Mercado', 'date' => '2026-11-03', 'amount' => '60', 'paid_by_member_id' => $b->id, 'split_mode' => 'equal', 'inputs' => [$a->id => 1, $b->id => 1, $c->id => 1]]);

        $r = $this->svc()->statementReport($g, '2026-11-01', '2026-11-30');
        $this->assertSame([$a->id => 2000, $b->id => -1000, $c->id => -1000], $r['opening']);
        $this->assertCount(1, $r['rows']);
        $this->assertSame(6000, $r['totals']['expenses']);
        $this->assertEquals([$a->id => 0, $b->id => 3000, $c->id => -3000], $r['closing']);
        $this->assertSame([['from' => $c->id, 'to' => $b->id, 'cents' => 3000]], array_map(fn ($t) => array_intersect_key($t, array_flip(['from', 'to', 'cents'])), $r['transfers']));

        $html = view('pdf.divisions.statement', ['group' => $g, 'members' => $g->members->keyBy('id'), 'report' => $r, 'rangeStart' => '2026-11-01', 'rangeEnd' => '2026-11-30',
            'generatedAt' => now(), 'logo' => 'data:image/png;base64,', 'money' => fn ($c) => $this->svc()->money($c)])->render();
        $this->assertStringContainsString('Vértice CM', $html);
        $this->assertStringContainsString('Saldo anterior ao período', $html);
        $this->assertStringContainsString('Quem paga quem', $html);
        $this->assertStringContainsString('Mercado', $html);
        $this->assertStringNotContainsString('Antiga', $html);

        $res = $this->get(route('divisions.pdf', ['group' => $g->id, 'de' => '2026-11-01', 'ate' => '2026-11-30']));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
        Volt::test('divisions.show', ['group' => $g])->assertSeeHtml('data-pdf-link');

        // Participante baixa do próprio grupo, não de outro.
        $p = User::factory()->create(['name' => 'Matheus', 'email' => 'matheus@example.com']);
        $p->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $other = $this->svc()->createGroup($admin, ['name' => 'Outro', 'type' => 'familia']);
        $this->actingAs($p);
        $this->get(route('divisions.pdf', $g->id))->assertOk();
        $this->get(route('divisions.pdf', $other->id))->assertForbidden();
    }

    public function test_money_decimal_parses_br_and_model_values(): void
    {
        $this->assertSame('12.5', Money::decimal('12,5'));
        $this->assertSame('12.5', Money::decimal('12.5'));
        $this->assertSame('33.333', Money::decimal('33,3333', 3));
        $this->assertSame('1234.567891', Money::decimal('1.234,567891', 6));
        $this->assertSame('1.234', Money::decimal('1.234', 6));
        $this->assertSame('1234567', Money::decimal('1.234.567', 6));
        $this->assertSame('2', Money::decimal('2 %'));
        $this->assertSame('-0.5', Money::decimal('-0,5'));
        $this->assertSame('', Money::decimal(''));
        $this->assertSame('', Money::decimal(null));
    }

    public function test_decimal_inputs_present_and_partnership_saves_comma(): void
    {
        $this->actingAs($this->admin());
        foreach (['resources/views/livewire/partnership/form.blade.php', 'resources/views/livewire/investments/operations/index.blade.php', 'resources/views/livewire/investments/dividends/index.blade.php',
            'resources/views/livewire/banking/receivables/index.blade.php', 'resources/views/livewire/banking/loans/index.blade.php', 'resources/views/livewire/banking/transactions/process-receipt-form.blade.php'] as $f) {
            $this->assertStringContainsString('x-decimal=', file_get_contents(base_path($f)), $f);
        }
        $this->assertStringContainsString("Alpine.directive('decimal'", file_get_contents(resource_path('js/app.js')));
    }
}

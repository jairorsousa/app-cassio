<?php

namespace Tests\Feature\Divisions;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Services\DivisionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Divisões: o Cassio escolhe a categoria do Financeiro DELE para a parte dele (separada da categoria do grupo). */
class DivisionsOwnerCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $part;

    private DivisionGroup $g;

    private array $m;

    private BankAccount $acc;

    private Category $rest;

    private Category $outros;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Cassio']);
        $this->admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->part = User::factory()->create(['name' => 'Heleno', 'email' => 'heleno@example.com']);
        $this->part->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => ['divisoes']])->save();
        $this->actingAs($this->admin);
        $s = $this->svc();
        $this->g = $s->createGroup($this->admin, ['name' => 'SHC', 'type' => 'familia', 'requires_approval' => false]);
        $h = $s->addMember($this->admin, $this->g, 'Heleno', 'heleno@example.com');
        $mc = $s->addMember($this->admin, $this->g, 'Sandro', 'sandro@example.com');
        $this->g->load('members');
        $this->m = ['c' => $this->g->ownerMember(), 'h' => $h, 's' => $mc];
        $s->claimMemberships($this->part);
        $this->acc = BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 1000, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        $lazer = Category::create(['name' => 'Lazer', 'type' => 'expense', 'status' => true]);
        $this->rest = Category::create(['name' => 'Restaurantes & Refeições', 'type' => 'expense', 'status' => true, 'parent_id' => $lazer->id]);
        $this->outros = Category::create(['name' => 'Outros', 'type' => 'expense', 'status' => true]);
    }

    private function svc(): DivisionService
    {
        return app(DivisionService::class);
    }

    private function data(string $payer, string $desc, array $extra = []): array
    {
        $d = $extra + ['description' => $desc, 'category' => 'alimentacao', 'date' => '2026-11-07', 'amount' => '124,00', 'paid_by_member_id' => $this->m[$payer]->id,
            'split_mode' => 'equal', 'inputs' => [$this->m['c']->id => 1, $this->m['h']->id => 1, $this->m['s']->id => 1]];
        if ($payer === 'c' && ! array_key_exists('payment_method', $d)) {
            $d += ['payment_method' => 'account', 'bank_account_id' => $this->acc->id];
        }

        return $d;
    }

    private function balances(): array
    {
        return BankAccount::all()->mapWithKeys(fn ($a) => [$a->id => $a->balance()])->all();
    }

    public function test_chosen_subcategory_goes_to_owner_share_and_own_payment_but_group_category_stays(): void
    {
        $e = $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'Pizza', ['owner_category_id' => $this->rest->id]))->first();
        $this->assertSame('alimentacao', $e->category, 'categoria do grupo não muda');
        $this->assertSame($this->rest->id, (int) $e->owner_category_id);
        $share = Transaction::where('source_type', 'division_share')->where('source_id', $e->id)->first();
        $this->assertSame($this->rest->id, (int) $share->category_id);
        $this->assertEquals(41.34, (float) $share->amount);

        $e2 = $this->svc()->saveExpense($this->admin, $this->g, $this->data('c', 'Jantar', ['owner_category_id' => $this->rest->id]))->first();
        $this->assertSame($this->rest->id, (int) Transaction::find($e2->transaction_id)->category_id, 'pago por ele: o lançamento da conta usa a categoria dele');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'X', ['owner_category_id' => Category::assetPurchaseCategoryId()]));
    }

    public function test_change_category_later_when_other_paid_and_participant_created_without_touching_balances(): void
    {
        $this->actingAs($this->part);
        $e = $this->svc()->saveExpense($this->part, $this->g->fresh(), $this->data('h', 'Pizza'))->first();
        $this->assertNull($e->owner_category_id, 'participante não escolhe a categoria do Cassio (e não há "Alimentação" no Financeiro)');
        $this->actingAs($this->admin);
        $share = Transaction::where('source_type', 'division_share')->where('source_id', $e->id)->first();
        $this->assertNull($share->category_id);
        $this->assertTrue($this->svc()->needsOwnerCategory($e->fresh(), $this->m['c']));
        $before = $this->balances();
        $audits = $e->audits()->count();

        $this->svc()->setOwnerCategory($this->admin, $e, $this->rest->id);
        $this->assertSame($this->rest->id, (int) $share->fresh()->category_id);
        $this->assertSame('alimentacao', $e->fresh()->category);
        $this->assertFalse($this->svc()->needsOwnerCategory($e->fresh(), $this->m['c']));
        $this->assertSame($before, $this->balances());
        $this->assertSame($audits, $e->audits()->count(), 'troca é só do Cassio: não aparece no histórico do grupo');
        $this->assertEquals(41.34, (float) $share->fresh()->amount);

        // participante não troca e nem vê a categoria do Cassio
        $this->actingAs($this->part);
        Volt::test('divisions.show', ['group' => $this->g])->assertDontSee('Restaurantes & Refeições')->assertDontSeeHtml('data-change-category')->assertDontSeeHtml('data-missing-banner');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->svc()->setOwnerCategory($this->part, $e->fresh(), $this->outros->id);
    }

    public function test_participant_edit_keeps_owner_category(): void
    {
        $this->g->update(['requires_approval' => true]);
        $this->actingAs($this->part);
        $e = $this->svc()->saveExpense($this->part, $this->g->fresh(), $this->data('h', 'Pizza'))->first();
        $this->assertSame('pending', $e->status);
        $this->svc()->setOwnerCategory($this->admin, $e, $this->rest->id);
        $this->svc()->saveExpense($this->part, $this->g->fresh(), $this->data('h', 'Pizza grande', ['owner_category_id' => $this->outros->id]), null, $e->fresh());
        $this->assertSame($this->rest->id, (int) $e->fresh()->owner_category_id);
    }

    public function test_missing_category_is_highlighted_and_chosen_from_division_screen(): void
    {
        $e = $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'Pizza Gud Gulla', ['owner_category_id' => null]))->first();
        $c = Volt::test('divisions.show', ['group' => $this->g])
            ->assertSeeHtml('data-missing-banner')->assertSee('1 despesa está sem a sua categoria no Financeiro')->assertSeeHtml('data-missing-category')->assertSee('Escolher categoria')
            ->call('openCategory', $e->id)->assertSeeHtml('data-category-modal')->assertSee('Restaurantes & Refeições')->assertSee('Lazer / Restaurantes & Refeições')
            ->set('quickCategoryId', $this->rest->id)->call('saveCategory')->assertHasNoErrors()
            ->assertDontSeeHtml('data-missing-banner')->assertSee('Sua categoria: Restaurantes & Refeições');
        $this->assertSame($this->rest->id, (int) Transaction::where('source_type', 'division_share')->value('category_id'));
    }

    public function test_form_picker_preselects_suggestion_and_saves_choice(): void
    {
        Volt::test('divisions.show', ['group' => $this->g])->call('newExpense')
            ->assertSeeHtml('data-owner-category-field')->assertSee('Sua categoria no Financeiro')->assertSet('ownerCategoryId', $this->outros->id)
            ->set('ownerCategoryId', $this->rest->id)
            ->set('expenseDescription', 'Pizza')->set('expenseAmount', '124,00')->set('expenseDate', '2026-11-07')->set('paidBy', (string) $this->m['h']->id)
            ->call('saveExpense')->assertHasNoErrors();
        $e = DivisionExpense::where('description', 'Pizza')->first();
        $this->assertSame($this->rest->id, (int) $e->owner_category_id);
        $this->assertSame('outros', $e->category);
        Volt::test('divisions.show', ['group' => $this->g])->call('editExpense', $e->id)->assertSet('ownerCategoryId', $this->rest->id);
    }

    public function test_financeiro_change_category_link_opens_modal(): void
    {
        $e = $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'Pizza', ['owner_category_id' => null]))->first();
        $this->get(route('banking.transactions.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()
            ->assertSee('Trocar categoria')->assertSee(route('divisions.show', ['group' => $this->g->id, 'categoria' => $e->id]), false);
        $this->get(route('divisions.show', ['group' => $this->g->id, 'categoria' => $e->id]))->assertOk()->assertSee('data-category-modal', false)->assertSee('Trocar categoria');
    }

    public function test_linked_manual_entry_gets_owner_category(): void
    {
        $tx = Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => '2026-11-07', 'amount' => 124, 'description' => 'Pizzaria', 'bank_account_id' => $this->acc->id, 'category_id' => $this->outros->id]);
        $before = $this->balances();
        $e = $this->svc()->saveExpense($this->admin, $this->g, $this->data('c', 'Pizza', ['payment_method' => null, 'transaction_id' => $tx->id, 'owner_category_id' => $this->rest->id]))->first();
        $this->assertSame($this->rest->id, (int) $tx->fresh()->category_id);
        $this->svc()->setOwnerCategory($this->admin, $e, $this->outros->id);
        $this->assertSame($this->outros->id, (int) $tx->fresh()->category_id);
        $this->assertSame($before, $this->balances());
    }

    public function test_backfill_copies_existing_entry_categories(): void
    {
        $a = $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'Taxa', ['category' => 'outros']))->first();
        $b = $this->svc()->saveExpense($this->admin, $this->g, $this->data('c', 'Carne'))->first();
        $c = $this->svc()->saveExpense($this->admin, $this->g, $this->data('h', 'Só dos outros', ['category' => 'outros', 'inputs' => [$this->m['h']->id => 1, $this->m['s']->id => 1]]))->first();
        DivisionExpense::query()->update(['owner_category_id' => null]);
        Transaction::where('source_type', 'division_expense')->update(['category_id' => null]);
        $before = $this->balances();
        $out = collect($this->svc()->backfillOwnerCategories())->keyBy('id');
        $this->assertSame($this->outros->id, (int) $a->fresh()->owner_category_id, 'copiou do lançamento "sua parte"');
        $this->assertNull($b->fresh()->owner_category_id, 'lançamento sem categoria continua sem');
        $this->assertSame($this->outros->id, (int) $c->fresh()->owner_category_id, 'sem lançamento: sugestão');
        $this->assertSame('sugestão pela categoria do grupo', $out[$c->id]['from']);
        $this->assertTrue(collect($this->svc()->backfillOwnerCategories())->pluck('category')->filter()->isEmpty(), 'rodar de novo não muda nada');
        $this->assertSame($before, $this->balances());
    }
}

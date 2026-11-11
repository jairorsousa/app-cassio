<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Financeiro › Lançamentos: escolher 25, 50 ou 100 por página, lembrado por usuário. */
class TransactionsPerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin('Cassio');
        $this->actingAs($this->admin);
        $acc = BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 0, 'initial_balance_date' => now()->startOfMonth()->toDateString(), 'status' => true]);
        $this->cat = Category::create(['name' => 'Mercado', 'type' => 'expense', 'status' => true]);
        $other = Category::create(['name' => 'Outros', 'type' => 'expense', 'status' => true]);
        for ($i = 1; $i <= 130; $i++) {
            Transaction::create(['type' => 'expense', 'status' => 'settled', 'date' => now()->startOfMonth()->addDays($i % 25)->toDateString(), 'amount' => $i,
                'description' => 'Item '.$i, 'bank_account_id' => $acc->id, 'category_id' => $i <= 70 ? $this->cat->id : $other->id]);
        }
    }

    private function makeAdmin(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    public function test_default_is_25_and_selector_is_shown(): void
    {
        Volt::test('banking.transactions.index')->assertSet('perPage', 25)
            ->assertViewHas('transactions', fn ($p) => $p->perPage() === 25 && $p->count() === 25 && $p->total() === 130)
            ->assertSeeHtml('data-per-page-select')->assertSee('Por página:');
        $this->get(route('banking.transactions.index'))->assertOk()->assertSee('Por página:')->assertSee('<option value="100">100</option>', false);
    }

    public function test_choosing_100_resets_to_page_one_keeps_filters_and_is_remembered(): void
    {
        $c = Volt::test('banking.transactions.index')->set('category', (string) $this->cat->id)
            ->call('gotoPage', 2)->assertSet('paginators.page', 2)
            ->set('perPage', 100)->assertSet('perPage', 100)->assertSet('paginators.page', 1)->assertSet('category', (string) $this->cat->id)
            ->assertViewHas('transactions', fn ($p) => $p->perPage() === 100 && $p->count() === 70 && $p->total() === 70);
        $c->call('clearFilters')->assertSet('perPage', 100)->assertViewHas('transactions', fn ($p) => $p->count() === 100 && $p->total() === 130);
        $this->assertSame(100, UserPreferences::perPage($this->admin->fresh()));
        Volt::test('banking.transactions.index')->assertSet('perPage', 100)->assertViewHas('transactions', fn ($p) => $p->count() === 100);

        $other = $this->makeAdmin('Outro');
        $this->actingAs($other);
        Volt::test('banking.transactions.index')->assertSet('perPage', 25);
    }

    public function test_only_25_50_100_are_accepted(): void
    {
        Volt::test('banking.transactions.index')->set('perPage', 50)->assertViewHas('transactions', fn ($p) => $p->perPage() === 50)
            ->set('perPage', 1000)->assertSet('perPage', 25)->assertViewHas('transactions', fn ($p) => $p->perPage() === 25)
            ->set('perPage', 7)->assertSet('perPage', 25);
        $this->assertSame(25, UserPreferences::perPage($this->admin->fresh()));
        $this->admin->forceFill(['preferences' => ['per_page' => ['transactions' => 999]]])->save();
        $this->assertSame(25, UserPreferences::perPage($this->admin->fresh()));
    }

    public function test_settings_save_keeps_per_page_and_per_page_keeps_theme(): void
    {
        $this->admin->forceFill(['preferences' => ['theme' => 'dark', 'accent' => 'azul', 'text_size' => 'padrao', 'density' => 'confortavel', 'home' => 'dashboard']])->save();
        UserPreferences::setPerPage($this->admin->fresh(), 50);
        $this->assertSame('dark', UserPreferences::for($this->admin->fresh())['theme']);
        Volt::test('settings.index')->set('accent', 'verde')->call('save')->assertHasNoErrors();
        $fresh = $this->admin->fresh();
        $this->assertSame(50, UserPreferences::perPage($fresh));
        $this->assertSame('verde', UserPreferences::for($fresh)['accent']);
    }
}

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

/** Financeiro › Lançamentos: ordenar por coluna (toque ordena, outro toque inverte), lembrado por usuário. */
class TransactionsSortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $btg;

    private BankAccount $amazonia;

    private Category $mercado;

    private Category $farmacia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin('Cassio');
        $this->actingAs($this->admin);
        $start = now()->startOfMonth()->toDateString();
        $this->btg = BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 0, 'initial_balance_date' => $start, 'status' => true]);
        $this->amazonia = BankAccount::create(['name' => 'Amazônia', 'type' => 'checking', 'initial_balance' => 0, 'initial_balance_date' => $start, 'status' => true]);
        $this->mercado = Category::create(['name' => 'Mercado', 'type' => 'expense', 'status' => true]);
        $this->farmacia = Category::create(['name' => 'Farmácia', 'type' => 'expense', 'status' => true]);
    }

    private function makeAdmin(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();

        return $u;
    }

    private function tx(int $day, string $type, float $amount, string $desc, ?Category $cat = null, ?BankAccount $acc = null): Transaction
    {
        return Transaction::create(['type' => $type, 'status' => 'settled', 'date' => \App\Support\BusinessDate::now()->startOfMonth()->addDays($day - 1)->toDateString(), 'amount' => $amount,
            'description' => $desc, 'bank_account_id' => ($acc ?? $this->btg)->id, 'category_id' => $cat?->id]);
    }

    private function descs($c): array
    {
        return $c->viewData('transactions')->getCollection()->pluck('description')->all();
    }

    public function test_default_is_newest_first_with_arrow_on_date(): void
    {
        $this->tx(1, 'expense', 10, 'Primeiro');
        $this->tx(5, 'expense', 10, 'Último');
        $c = Volt::test('banking.transactions.index')->assertSet('sortBy', 'date')->assertSet('sortDir', 'desc')
            ->assertSeeHtml('data-sort-active="desc"')->assertSeeHtml('data-sort-col="amount"')->assertSeeHtml('data-sort-select');
        $this->assertSame(['Último', 'Primeiro'], $this->descs($c));
    }

    public function test_date_ascending_resets_page_keeps_filters_and_per_page_and_is_remembered(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->tx(($i % 20) + 1, 'expense', $i, 'Item '.$i, $i <= 26 ? $this->mercado : $this->farmacia);
        }
        $c = Volt::test('banking.transactions.index')->set('category', (string) $this->mercado->id)->set('perPage', 25)
            ->call('gotoPage', 2)->assertSet('paginators.page', 2)
            ->call('sortByColumn', 'date')->assertSet('sortBy', 'date')->assertSet('sortDir', 'asc')
            ->assertSet('paginators.page', 1)->assertSet('category', (string) $this->mercado->id)->assertSet('perPage', 25)
            ->assertSeeHtml('data-sort-active="asc"');
        $rows = $c->viewData('transactions');
        $this->assertSame(26, $rows->total());
        $dates = $rows->getCollection()->map(fn ($t) => $t->date->toDateString().'#'.str_pad((string) $t->id, 5, '0', STR_PAD_LEFT))->all();
        $sorted = $dates; sort($sorted);
        $this->assertSame($sorted, $dates, 'data crescente, desempate por id crescente');
        $c->call('clearFilters')->assertSet('sortDir', 'asc');
        $this->assertSame(['date', 'asc'], UserPreferences::sort($this->admin->fresh()));
        Volt::test('banking.transactions.index')->assertSet('sortDir', 'asc');
        $this->actingAs($this->makeAdmin('Outro'));
        Volt::test('banking.transactions.index')->assertSet('sortBy', 'date')->assertSet('sortDir', 'desc');
    }

    public function test_amount_uses_signed_value_and_second_tap_inverts(): void
    {
        $this->tx(1, 'income', 500, 'Receita 500');
        $this->tx(2, 'expense', 20, 'Despesa 20');
        $this->tx(3, 'expense', 900, 'Despesa 900');
        $this->tx(4, 'transfer', -100, 'Transferência saída 100');
        $this->tx(5, 'expense', -30, 'Estorno 30');
        $c = Volt::test('banking.transactions.index')->call('sortByColumn', 'amount')->assertSet('sortDir', 'asc');
        $this->assertSame(['Despesa 900', 'Transferência saída 100', 'Despesa 20', 'Estorno 30', 'Receita 500'], $this->descs($c));
        $c->call('sortByColumn', 'amount')->assertSet('sortDir', 'desc');
        $this->assertSame(['Receita 500', 'Estorno 30', 'Despesa 20', 'Transferência saída 100', 'Despesa 900'], $this->descs($c));
    }

    public function test_category_and_account_sort_by_displayed_name_with_stable_tiebreak(): void
    {
        $a = $this->tx(3, 'expense', 10, 'Mercado A', $this->mercado);
        $b = $this->tx(3, 'expense', 11, 'Mercado B', $this->mercado);
        $this->tx(1, 'expense', 12, 'Farmácia', $this->farmacia, $this->amazonia);
        $this->tx(2, 'expense', 13, 'Sem categoria');
        $rateado = $this->tx(4, 'expense', 14, 'Rateado');
        $rateado->allocations()->forceCreate(['category_id' => $this->mercado->id, 'amount' => 7]);
        $rateado->allocations()->forceCreate(['category_id' => $this->farmacia->id, 'amount' => 7]);
        $c = Volt::test('banking.transactions.index')->call('sortByColumn', 'category');
        // '' < Farmácia (= rateado pelo menor nome; desempate data desc) < Mercado (mesma data: id desc)
        $this->assertSame(['Sem categoria', 'Rateado', 'Farmácia', 'Mercado B', 'Mercado A'], $this->descs($c));
        $c->call('sortByColumn', 'account')->assertSet('sortDir', 'asc');
        $this->assertSame('Farmácia', $this->descs($c)[0]); // Amazônia antes de BTG
        $c->call('sortByColumn', 'description');
        $this->assertSame(['Farmácia', 'Mercado A', 'Mercado B', 'Rateado', 'Sem categoria'], $this->descs($c));
    }

    public function test_mobile_select_and_invalid_values(): void
    {
        $this->tx(1, 'expense', 10, 'X');
        Volt::test('banking.transactions.index')->set('sortBy', 'category')->assertSet('sortBy', 'category')->assertSet('sortDir', 'asc')
            ->call('toggleSortDir')->assertSet('sortDir', 'desc')
            ->set('sortBy', 'id; drop table users')->assertSet('sortBy', 'date')->assertSet('sortDir', 'desc')
            ->call('sortByColumn', 'nada')->assertSet('sortBy', 'date');
        $this->assertSame(['date', 'desc'], UserPreferences::validSort('x', 'y'));
        $this->assertSame(['amount', 'asc'], UserPreferences::validSort('amount', 'sideways'));
        $u = $this->admin->fresh();
        $u->forceFill(['preferences' => ['sort' => ['transactions' => ['by' => 'evil', 'dir' => 'desc']], 'per_page' => ['transactions' => 50]]])->save();
        $this->assertSame(['date', 'desc'], UserPreferences::sort($u->fresh()));
        UserPreferences::setSort($u, 'amount', 'desc');
        $this->assertSame(50, UserPreferences::perPage($u->fresh()), 'ordenar não apaga o por página');
    }
}

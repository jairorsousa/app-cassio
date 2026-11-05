<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Receivable;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa1BaseFinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attributes = []): BankAccount
    {
        return BankAccount::create($attributes + ['name' => 'BB Corrente', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
    }

    public function test_balance_starts_at_the_dated_initial_balance(): void
    {
        $account = $this->account(['initial_balance' => 1000, 'initial_balance_date' => '2026-10-01']);
        $service = app(TransactionService::class);
        $service->create(['type' => 'expense', 'date' => '2026-09-20', 'amount' => 300, 'description' => 'Antes do corte', 'bank_account_id' => $account->id]);
        $service->create(['type' => 'income', 'date' => '2026-10-01', 'amount' => 200, 'description' => 'No corte', 'bank_account_id' => $account->id]);
        $service->create(['type' => 'expense', 'date' => '2026-10-10', 'amount' => 50, 'description' => 'Depois', 'bank_account_id' => $account->id]);

        $this->assertEquals(1150.0, $account->balance());
        $this->assertEquals(1200.0, $account->balance('2026-10-05'));

        $undated = $this->account(['name' => 'Sem data', 'initial_balance' => 100]);
        $service->create(['type' => 'expense', 'date' => '2026-01-01', 'amount' => 30, 'description' => 'Antigo', 'bank_account_id' => $undated->id]);
        $this->assertEquals(70.0, $undated->balance());
    }

    public function test_historical_entries_stay_out_of_balance_and_month_result(): void
    {
        $account = $this->account(['initial_balance' => 500, 'initial_balance_date' => '2026-10-15']);
        $old = app(TransactionService::class)->create(['type' => 'expense', 'date' => '2026-10-02', 'amount' => 400, 'description' => 'Pagamento de requisitório antigo', 'bank_account_id' => $account->id]);

        $this->assertTrue($old->fresh()->isHistorical());
        $this->assertEquals(500.0, $account->balance());
        $totals = app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'cash');
        $this->assertEquals(0.0, $totals['expense']);
        // Continua existindo para o módulo de origem.
        $this->assertNotNull(Transaction::find($old->id));
    }

    public function test_transfer_gets_system_category_and_stays_out_of_result(): void
    {
        $from = $this->account(['initial_balance' => 1000]);
        $to = $this->account(['name' => 'BB Investimento', 'type' => 'investment', 'linked_account_id' => $from->id]);
        $out = app(TransferService::class)->execute($from, $to, 300, now(), 'Aplicação');

        $category = Category::findOrFail(Category::transferCategoryId());
        $this->assertTrue($category->is_system);
        $this->assertSame('Transferência entre contas', $category->name);
        $this->assertSame($category->id, $out->category_id);
        $this->assertSame($category->id, $out->related->category_id);

        $totals = app(OwnFinancialService::class)->totals(now()->startOfMonth(), now()->endOfMonth(), 'cash');
        $this->assertEquals(0.0, $totals['result']);
        $this->assertEquals(700.0, $from->balance());
        $this->assertEquals(300.0, $to->balance());

        $this->expectException(\DomainException::class);
        $category->update(['name' => 'Outra']);
    }

    public function test_system_category_cannot_be_deleted(): void
    {
        $this->expectException(\DomainException::class);
        Category::findOrFail(Category::transferCategoryId())->delete();
    }

    public function test_cash_and_accrual_regimes_for_card_purchases(): void
    {
        $account = $this->account(['initial_balance' => 1000]);
        $card = CreditCard::create(['name' => 'Nubank', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        app(InstallmentService::class)->split($card, Carbon::parse('2026-10-10'), 500, 1, 'Compra no cartão');
        $service = app(OwnFinancialService::class);

        $cash = $service->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'cash');
        $accrual = $service->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'accrual');
        $this->assertEquals(0.0, $cash['expense']);
        $this->assertEquals(500.0, $accrual['expense']);

        // Dashboard e Financeiro usam o mesmo regime escolhido pelo usuário.
        $user = User::factory()->create(['financial_regime' => 'accrual']);
        $this->actingAs($user);
        $this->assertSame('accrual', OwnFinancialService::currentRegime());
        Volt::test('banking.regime-switch')->set('regime', 'cash');
        $this->assertSame('cash', $user->fresh()->financial_regime);
    }

    public function test_dashboard_and_financeiro_match_in_both_regimes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = $this->account(['initial_balance' => 1000]);
        app(TransactionService::class)->create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => 3000, 'description' => 'Honorários', 'bank_account_id' => $account->id, 'category_id' => Category::create(['name' => 'Honorários', 'type' => 'income'])->id]);
        $card = CreditCard::create(['name' => 'Nubank', 'limit' => 5000, 'closing_day' => 28, 'due_day' => 5]);
        app(InstallmentService::class)->split($card, Carbon::today(), 500, 1, 'Compra no cartão');

        foreach (['cash' => 3000.0, 'accrual' => 2500.0] as $regime => $expected) {
            $user->forceFill(['financial_regime' => $regime])->save();
            \Illuminate\Support\Facades\Cache::flush();
            Livewire::withoutLazyLoading();
            $financeiro = Volt::test('banking.dashboard');
            Livewire::withoutLazyLoading();
            $dashboard = Volt::test('dashboard.index');
            $this->assertEquals($expected, $financeiro->viewData('result'), $regime);
            $this->assertEquals($expected, $dashboard->viewData('snapshot')['month']['result'], $regime);
        }
    }

    public function test_test_records_stay_out_of_totals_and_balances(): void
    {
        $account = $this->account(['initial_balance' => 0]);
        app(TransactionService::class)->create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => 3000, 'description' => 'TESTE', 'bank_account_id' => $account->id, 'is_test' => true]);
        app(TransactionService::class)->create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => 100, 'description' => 'Real', 'bank_account_id' => $account->id]);

        $this->assertEquals(100.0, $account->balance());
        $this->assertEquals(100.0, app(OwnFinancialService::class)->totals(now()->startOfMonth(), now()->endOfMonth(), 'cash')['income']);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(2, Transaction::withTests()->count());

        $contact = Contact::create(['name' => 'TESTE TESTE', 'type' => 'pessoal', 'is_test' => true]);
        $this->assertNull(Contact::find($contact->id));
        $this->assertNotNull(Contact::withTests()->find($contact->id));
        Receivable::create(['contact_id' => $contact->id, 'description' => 'teste', 'date' => now()->toDateString(), 'repayment_mode' => 'undated', 'is_test' => true]);
        $this->assertSame(0, Receivable::count());
    }

    public function test_category_is_required_for_income_and_expense_in_the_modal(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        Volt::test('banking.transactions.index')
            ->call('create', 'expense')
            ->set('formDate', now()->toDateString())
            ->set('formAmount', '10')
            ->set('formDescription', 'Sem categoria')
            ->set('formBankAccountId', $account->id)
            ->call('saveTransaction')
            ->assertHasErrors(['formCategoryId' => 'required']);
        $this->assertSame(0, Transaction::count());
    }

    public function test_edit_route_opens_the_shared_modal(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $transaction = app(TransactionService::class)->create(['type' => 'expense', 'date' => now()->toDateString(), 'amount' => 10, 'description' => 'Café', 'bank_account_id' => $account->id]);

        $this->get(route('banking.transactions.edit', $transaction))
            ->assertRedirect(route('banking.transactions.index', ['editar' => $transaction->id]));
    }

    public function test_conferir_saldo_shows_difference(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account(['initial_balance' => 1000, 'initial_balance_date' => '2026-10-01']);
        app(TransactionService::class)->create(['type' => 'expense', 'date' => '2026-10-05', 'amount' => 100, 'description' => 'Conta', 'bank_account_id' => $account->id]);

        $component = Volt::test('banking.accounts.index')
            ->call('openCheck', $account->id)
            ->set('checkDate', '2026-10-31')
            ->set('checkStatement', '850,00')
            ->call('runCheck')
            ->assertHasNoErrors();
        $this->assertEquals(900.0, $component->get('checkResult')['calculated']);
        $this->assertEquals(-50.0, $component->get('checkResult')['difference']);
    }
}

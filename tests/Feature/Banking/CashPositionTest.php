<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Services\CashPositionService;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Banking\Services\TransferService;
use App\Domains\Dashboard\Models\DashboardSnapshot;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CashPositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_balance_keeps_third_party_money_out_of_the_holder_total(): void
    {
        BankAccount::create(['name' => 'BB Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'status' => true]);
        BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => -400, 'status' => true]);
        BankAccount::create(['name' => 'Repasse quitado a maior', 'type' => 'repasse', 'initial_balance' => 50, 'status' => true]);
        BankAccount::create(['name' => 'Inativa', 'type' => 'checking', 'initial_balance' => 9999, 'status' => false]);

        $cash = app(CashPositionService::class)->summarize();

        $this->assertEquals(1000.0, $cash['accounts_balance']);
        $this->assertEquals(350.0, $cash['third_party_balance']);
        $this->assertEquals(650.0, $cash['own_balance']);
    }

    public function test_dashboard_and_financeiro_show_the_same_cash_and_month_result(): void
    {
        $this->actingAs(User::factory()->create(['financial_regime' => 'cash']));
        $checking = BankAccount::create(['name' => 'BB Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'status' => true]);
        $savings = BankAccount::create(['name' => 'BB Poupança', 'type' => 'savings', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => -400, 'status' => true]);

        app(TransactionService::class)->create([
            'type' => 'income',
            'date' => now()->toDateString(),
            'amount' => 3000,
            'description' => 'Honorários',
            'bank_account_id' => $checking->id,
        ]);
        $card = CreditCard::create(['name' => 'Nubank', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        app(InstallmentService::class)->split($card, Carbon::today(), 500, 1, 'Compra no cartão');
        app(TransferService::class)->execute($checking, $savings, 100, now(), 'Transferência entre contas');

        Livewire::withoutLazyLoading();
        $financeiro = Volt::test('banking.dashboard');
        Livewire::withoutLazyLoading();
        $dashboard = Volt::test('dashboard.index');

        $this->assertEquals(4000.0, $financeiro->viewData('accountsBalance'));
        $this->assertEquals(400.0, $financeiro->viewData('thirdPartyBalance'));
        $this->assertEquals(3600.0, $financeiro->viewData('ownBalance'));
        $this->assertEquals(3000.0, $financeiro->viewData('result'));

        $patrimony = $dashboard->viewData('snapshot')['patrimony'];
        $this->assertEquals(4000.0, $patrimony['accounts_balance']);
        $this->assertEquals(400.0, $patrimony['third_party_balance']);
        $this->assertEquals(3600.0, $patrimony['own_balance']);
        $this->assertEquals(3000.0, $dashboard->viewData('snapshot')['month']['result']);

        $financeiro->assertSee('Saldo nas contas')->assertSee('Dinheiro de terceiros')->assertSee('Saldo próprio')
            ->assertSee('4.000,00')->assertSee('400,00')->assertSee('3.600,00')->assertSee('3.000,00');
        $dashboard->assertSee('Saldo nas contas')->assertSee('Dinheiro de terceiros')->assertSee('Saldo próprio')
            ->assertSee('4.000,00')->assertSee('400,00')->assertSee('3.600,00')->assertSee('3.000,00');
    }

    public function test_dashboard_reads_live_balances_instead_of_a_stale_snapshot(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100, 'status' => true]);
        $service = app(DashboardSnapshotService::class);
        $service->refresh();
        $this->assertEquals(100.0, DashboardSnapshot::query()->latest('id')->first()->payload['patrimony']['own_balance']);

        Cache::forget(DashboardSnapshotService::CACHE_KEY);
        app(TransactionService::class)->create([
            'type' => 'income',
            'date' => now()->toDateString(),
            'amount' => 50,
            'description' => 'Pix',
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(150.0, $service->current()['patrimony']['own_balance']);
    }

    public function test_lancamento_shortcut_opens_the_transaction_modal(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('banking.transactions.create', ['type' => 'income']))
            ->assertRedirect(route('banking.transactions.index', ['novo' => 'income']));

        $this->get(route('banking.transactions.index', ['novo' => 'income']))
            ->assertOk()
            ->assertSee('Recebimento de processo')
            ->assertSee('Dividir este lançamento em categorias');

        $this->get(route('banking.transactions.index', ['novo' => 'expense']))
            ->assertOk()
            ->assertSee('Dividir este lançamento em categorias');
    }
}

<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Dashboard\Models\DashboardSnapshot;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_aggregates_balance_and_month_result(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);

        app(TransactionService::class)->create([
            'type' => 'income', 'date' => now()->format('Y-m-d'),
            'amount' => 500, 'description' => 'Receita',
            'bank_account_id' => $account->id,
        ]);
        app(TransactionService::class)->create([
            'type' => 'expense', 'date' => now()->format('Y-m-d'),
            'amount' => 200, 'description' => 'Despesa',
            'bank_account_id' => $account->id,
        ]);

        $payload = app(DashboardSnapshotService::class)->refresh();

        $this->assertEquals(1300.0, $payload['patrimony']['cash_balance']);
        $this->assertEquals(500.0, $payload['month']['income']);
        $this->assertEquals(200.0, $payload['month']['expense']);
        $this->assertEquals(300.0, $payload['month']['result']);
    }

    public function test_open_invoices_are_subtracted_from_patrimony(): void
    {
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 2000]);

        app(InstallmentService::class)->split($card, Carbon::today(), 600, 1, 'Compra');

        $payload = app(DashboardSnapshotService::class)->refresh();

        $this->assertEquals(2000.0, $payload['patrimony']['cash_balance']);
        $this->assertEquals(600.0, $payload['patrimony']['open_invoices_total']);
        $this->assertEquals(1400.0, $payload['patrimony']['total']);
    }

    public function test_snapshot_is_persisted_and_cached(): void
    {
        BankAccount::create(['name' => 'Conta', 'initial_balance' => 100]);

        $service = app(DashboardSnapshotService::class);
        $service->refresh();

        $this->assertEquals(1, DashboardSnapshot::count());
        $this->assertNotNull(Cache::get(DashboardSnapshotService::CACHE_KEY));
    }

    public function test_observer_invalidates_cache_on_transaction_save(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);

        $service = app(DashboardSnapshotService::class);
        $service->refresh();
        $this->assertNotNull(Cache::get(DashboardSnapshotService::CACHE_KEY));

        app(TransactionService::class)->create([
            'type' => 'income', 'date' => now()->format('Y-m-d'),
            'amount' => 50, 'description' => 'Pix',
            'bank_account_id' => $account->id,
        ]);

        $this->assertNull(Cache::get(DashboardSnapshotService::CACHE_KEY));
    }
}

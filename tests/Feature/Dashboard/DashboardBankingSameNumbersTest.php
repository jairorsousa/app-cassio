<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardBankingSameNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_banking_overview_matches_dashboard_widgets_and_is_not_cached(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true])->save();
        $this->actingAs($u);
        \Livewire\Livewire::withoutLazyLoading();
        $acc = BankAccount::forceCreate(['name' => 'CC', 'type' => 'checking', 'initial_balance' => 1000, 'status' => true]);
        Transaction::forceCreate(['type' => 'expense', 'date' => now()->toDateString(), 'amount' => '200.00', 'description' => 'X', 'status' => 'settled', 'bank_account_id' => $acc->id]);
        $w = app(\App\Domains\Dashboard\Services\DashboardWidgetService::class)->build(\App\Domains\Dashboard\Support\ReportPeriod::month(now()->format('Y-m')));
        $fmt = fn ($v) => number_format(abs($v), 2, ',', '.');
        $html = $this->get(route('banking.dashboard'))->assertOk()->getContent();
        \Livewire\Livewire::withoutLazyLoading();
        $c = Volt::test('banking.dashboard');
        $c->assertSee('Resultado próprio do mês')->assertSee($fmt($w['flows']['result']))->assertSee($fmt($w['cash']['own_balance']));
        $this->assertSame(-200.0, (float) $w['flows']['result']);
        // nova despesa aparece na hora (sem cache de 5 min)
        Transaction::forceCreate(['type' => 'expense', 'date' => now()->toDateString(), 'amount' => '33.00', 'description' => 'Y', 'status' => 'settled', 'bank_account_id' => $acc->id]);
        \Livewire\Livewire::withoutLazyLoading();
        Volt::test('banking.dashboard')->assertSee('233,00');
    }
}

<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Dashboard\Services\DashboardSnapshotBuilder;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Services\AssetPositionService;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Writs\Models\Writ;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsolidatedSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_patrimony_aggregates_all_modules(): void
    {
        // Banking: saldo R$ 5.000
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);

        // Investments: comprou R$ 3.000, cotação subiu para R$ 3.500
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $asset = Asset::create(['ticker' => 'PETR4', 'name' => 'Petro', 'asset_class_id' => $class->id]);
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 100, 'unit_price' => 30, 'fees' => 0, 'total' => 3000,
        ]);
        app(AssetPositionService::class)->setQuote($asset, '2026-04-29', 35.00);

        // Partnership: aportou R$ 2.000 (sem distribuição) → exposto R$ 2.000
        $partnership = Partnership::create([
            'name' => 'Escritório', 'participation_percentage' => 30,
            'joined_at' => '2026-01-01', 'status' => true,
        ]);
        PartnershipContribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-02-10',
            'amount' => 2000, 'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        // Writs: capital em risco R$ 1.500 (paid stage)
        $writ = Writ::create([
            'type' => 'rpv', 'stage' => 'paid',
            'face_value' => 3000, 'paid_amount' => 1500,
            'estimated_receipt_amount' => 3000, 'paid_at' => '2026-03-01',
        ]);

        $payload = app(DashboardSnapshotBuilder::class)->build();

        // Saldo bruto: 5000 (Banking) + 3500 (carteira) + 2000 (sociedade) + 1500 (writs)
        // = 12000, sem faturas abertas
        $this->assertEquals(5000.0 - 2000.0, $payload['patrimony']['cash_balance']); // -2000 do aporte
        $this->assertEquals(3500.0, $payload['patrimony']['portfolio_market_value']);
        $this->assertEquals(2000.0, $payload['patrimony']['partnership_exposed']);
        $this->assertEquals(1500.0, $payload['patrimony']['writs_capital_at_risk']);
        $this->assertEquals(0.0, $payload['patrimony']['open_invoices_total']);

        // Total = 3000 + 3500 + 2000 + 1500 = 10000
        $this->assertEquals(10000.0, $payload['patrimony']['total']);
    }

    public function test_open_invoices_subtract_from_patrimony(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);

        app(InstallmentService::class)->split($card, Carbon::today(), 1500, 1, 'Compra');

        $payload = app(DashboardSnapshotBuilder::class)->build();

        $this->assertEquals(1500.0, $payload['patrimony']['open_invoices_total']);
        $this->assertEquals(5000.0 - 1500.0, $payload['patrimony']['total']);
    }

    public function test_distribution_excludes_zero_slices(): void
    {
        BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);

        $payload = app(DashboardSnapshotBuilder::class)->build();

        $labels = collect($payload['distribution'])->pluck('label')->all();
        $this->assertContains('Caixa em contas', $labels);
        // Sem investimentos, sociedades, requisitórios → não devem aparecer
        $this->assertNotContains('Carteira · Ações', $labels);
        $this->assertNotContains('Sociedade (capital exposto)', $labels);
        $this->assertNotContains('Requisitórios em aberto', $labels);
    }

    public function test_writs_by_stage_returns_all_five_stages(): void
    {
        $payload = app(DashboardSnapshotBuilder::class)->build();

        $stages = collect($payload['writs']['by_stage'])->pluck('stage')->all();
        $this->assertEquals(['negotiation', 'pending', 'paid', 'petitioning', 'finalized'], $stages);
    }

    public function test_future_contributions_lists_pending(): void
    {
        $partnership = Partnership::create([
            'name' => 'P', 'participation_percentage' => 25, 'status' => true,
        ]);
        PartnershipContribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-06-01',
            'amount' => 500, 'status' => 'pending',
        ]);
        PartnershipContribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-04-01',
            'amount' => 200, 'status' => 'done',
        ]);

        $payload = app(DashboardSnapshotBuilder::class)->build();

        $this->assertCount(1, $payload['future_contributions']);
        $this->assertEquals(500.0, $payload['future_contributions'][0]['amount']);
    }

    public function test_quote_change_invalidates_snapshot_cache(): void
    {
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $asset = Asset::create(['ticker' => 'XPTO3', 'name' => 'X', 'asset_class_id' => $class->id]);
        AssetOperation::create([
            'asset_id' => $asset->id, 'date' => '2026-01-10', 'type' => 'buy',
            'quantity' => 10, 'unit_price' => 100, 'fees' => 0, 'total' => 1000,
        ]);

        $service = app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class);
        $service->refresh();
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(\App\Domains\Dashboard\Services\DashboardSnapshotService::CACHE_KEY));

        // Atualiza cotação → deve invalidar
        app(AssetPositionService::class)->setQuote($asset, '2026-04-29', 110);

        $this->assertNull(\Illuminate\Support\Facades\Cache::get(\App\Domains\Dashboard\Services\DashboardSnapshotService::CACHE_KEY));
    }

    public function test_writ_field_edit_invalidates_snapshot_cache(): void
    {
        $writ = Writ::create([
            'type' => 'rpv', 'stage' => 'paid',
            'face_value' => 1000, 'paid_amount' => 500, 'estimated_receipt_amount' => 1000,
            'paid_at' => '2026-03-01',
        ]);

        $service = app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class);
        $service->refresh();
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(\App\Domains\Dashboard\Services\DashboardSnapshotService::CACHE_KEY));

        $writ->update(['paid_amount' => 700]);

        $this->assertNull(\Illuminate\Support\Facades\Cache::get(\App\Domains\Dashboard\Services\DashboardSnapshotService::CACHE_KEY));
    }

    public function test_averages_include_three_six_twelve_months(): void
    {
        $payload = app(DashboardSnapshotBuilder::class)->build();

        $this->assertArrayHasKey('last_3_months', $payload['averages']);
        $this->assertArrayHasKey('last_6_months', $payload['averages']);
        $this->assertArrayHasKey('last_12_months', $payload['averages']);
    }
}

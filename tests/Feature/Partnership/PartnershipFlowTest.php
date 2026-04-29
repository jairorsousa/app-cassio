<?php

namespace Tests\Feature\Partnership;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Partnership\Models\PartnershipExpense;
use App\Domains\Partnership\Services\PartnershipProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PartnershipFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makePartnership(float $pct = 30.0): Partnership
    {
        return Partnership::create([
            'name' => 'Escritório Cássio & Associados',
            'cnpj' => '00.000.000/0001-00',
            'participation_percentage' => $pct,
            'joined_at' => '2026-01-01',
            'status' => true,
        ]);
    }

    public function test_done_contribution_creates_expense_in_account(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000]);
        $partnership = $this->makePartnership();

        PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 25000,
            'status' => 'done',
            'bank_account_id' => $account->id,
            'purpose' => 'Aporte inicial',
        ]);

        $this->assertEquals(75000.0, $account->fresh()->balance());
        $this->assertEquals(25000.0, $partnership->fresh()->totalContributed());
    }

    public function test_pending_contribution_does_not_create_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);
        $partnership = $this->makePartnership();

        PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 500,
            'status' => 'pending',
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(1000.0, $account->fresh()->balance());
        $this->assertEquals(0.0, $partnership->fresh()->totalContributed());
    }

    public function test_marking_pending_as_done_creates_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 500,
            'status' => 'pending',
            'bank_account_id' => $account->id,
        ]);

        $c->update(['status' => 'done']);

        $this->assertEquals(500.0, $account->fresh()->balance());
    }

    public function test_partnership_expense_uses_proportional_amount(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);
        $partnership = $this->makePartnership(30.0);

        PartnershipExpense::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-15',
            'total_amount' => 1000,
            'applied_percentage' => 30.0,
            'proportional_amount' => 300,
            'description' => 'Aluguel do escritório',
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(4700.0, $account->fresh()->balance());
        $this->assertEquals(300.0, $partnership->fresh()->totalExpenses());
    }

    public function test_distribution_creates_income(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);
        $partnership = $this->makePartnership();

        PartnershipDistribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-30',
            'amount' => 5000,
            'bank_account_id' => $account->id,
            'source' => 'Lucro Q1/2026',
        ]);

        $this->assertEquals(5000.0, $account->fresh()->balance());
        $this->assertEquals(5000.0, $partnership->fresh()->totalDistributions());
    }

    public function test_profitability_summary_aggregates_correctly(): void
    {
        $partnership = $this->makePartnership(30.0);
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000]);

        PartnershipContribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-01-15',
            'amount' => 20000, 'status' => 'done', 'bank_account_id' => $account->id,
        ]);
        PartnershipExpense::create([
            'partnership_id' => $partnership->id, 'date' => '2026-02-15',
            'total_amount' => 1000, 'applied_percentage' => 30,
            'proportional_amount' => 300, 'description' => 'Despesa',
            'bank_account_id' => $account->id,
        ]);
        PartnershipDistribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-04-15',
            'amount' => 25000, 'bank_account_id' => $account->id,
        ]);

        $summary = app(PartnershipProfitabilityService::class)->summary($partnership);

        $this->assertEquals(20000.0, $summary['total_contributed']);
        $this->assertEquals(300.0, $summary['total_expenses']);
        $this->assertEquals(20300.0, $summary['total_invested']);
        $this->assertEquals(25000.0, $summary['total_distributions']);
        $this->assertEquals(4700.0, $summary['net_result']);
        $this->assertGreaterThan(0, $summary['roi_percent']);
    }

    public function test_profitability_filters_by_period(): void
    {
        $partnership = $this->makePartnership();
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000]);

        PartnershipContribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-01-10',
            'amount' => 10000, 'status' => 'done', 'bank_account_id' => $account->id,
        ]);
        PartnershipDistribution::create([
            'partnership_id' => $partnership->id, 'date' => '2026-04-10',
            'amount' => 3000, 'bank_account_id' => $account->id,
        ]);

        $summary = app(PartnershipProfitabilityService::class)->summary(
            $partnership,
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30')
        );

        $this->assertEquals(0.0, $summary['total_contributed'], 'aporte fora do período');
        $this->assertEquals(3000.0, $summary['total_distributions']);
    }

    public function test_polymorphic_link_in_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 10000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10', 'amount' => 1000, 'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        $tx = $c->transactions()->first();
        $this->assertNotNull($tx);
        $this->assertTrue($tx->isReadOnly());
        $this->assertEquals('expense', $tx->type);
        $this->assertEquals(1000.0, (float) $tx->amount);
    }
}

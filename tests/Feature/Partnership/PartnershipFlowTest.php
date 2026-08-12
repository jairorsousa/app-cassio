<?php

namespace Tests\Feature\Partnership;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Partnership\Models\PartnershipExpense;
use App\Domains\Partnership\Services\PartnershipLedgerService;
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

    public function test_updating_done_contribution_updates_banking_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 10000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 1000,
            'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        $c->update(['amount' => 4000, 'date' => '2026-04-20']);

        $tx = $c->transactions()->first();
        $this->assertNotNull($tx);
        $this->assertEquals(4000.0, (float) $tx->amount);
        $this->assertEquals('2026-04-20', $tx->date->toDateString());
        $this->assertEquals(6000.0, $account->fresh()->balance());
    }

    public function test_reverting_contribution_to_pending_removes_banking_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 1000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 400,
            'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        $this->assertEquals(600.0, $account->fresh()->balance());

        $c->update(['status' => 'pending']);

        $this->assertNull($c->transactions()->first());
        $this->assertEquals(1000.0, $account->fresh()->balance());
        $this->assertEquals(0.0, $partnership->fresh()->totalContributed());
    }

    public function test_adding_account_to_done_contribution_creates_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 2000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 500,
            'status' => 'done',
        ]);

        $this->assertEquals(2000.0, $account->fresh()->balance());
        $this->assertNull($c->transactions()->first());

        $c->update(['bank_account_id' => $account->id]);

        $this->assertNotNull($c->fresh()->transactions()->first());
        $this->assertEquals(1500.0, $account->fresh()->balance());
    }

    public function test_deleting_contribution_removes_banking_expense(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 1500,
            'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        $txId = $c->transactions()->first()?->id;
        $this->assertNotNull($txId);
        $this->assertEquals(3500.0, $account->fresh()->balance());

        $c->delete();

        $this->assertSoftDeleted('partnership_contributions', ['id' => $c->id]);
        $this->assertSoftDeleted('transactions', ['id' => $txId]);
        $this->assertEquals(5000.0, $account->fresh()->balance());
    }

    public function test_updating_expense_updates_proportional_banking_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);
        $partnership = $this->makePartnership(30.0);

        $e = PartnershipExpense::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-15',
            'total_amount' => 1000,
            'applied_percentage' => 30.0,
            'proportional_amount' => 300,
            'description' => 'Aluguel',
            'bank_account_id' => $account->id,
        ]);

        $e->update([
            'total_amount' => 2000,
            'applied_percentage' => 50.0,
            'proportional_amount' => 1000,
            'description' => 'Aluguel + condomínio',
        ]);

        $tx = $e->transactions()->first();
        $this->assertEquals(1000.0, (float) $tx->amount);
        $this->assertStringContainsString('50,00%', $tx->description);
        $this->assertStringContainsString('Aluguel + condomínio', $tx->description);
        $this->assertEquals(4000.0, $account->fresh()->balance());
    }

    public function test_deleting_expense_removes_banking_expense(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 5000]);
        $partnership = $this->makePartnership(30.0);

        $e = PartnershipExpense::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-15',
            'total_amount' => 1000,
            'applied_percentage' => 30.0,
            'proportional_amount' => 300,
            'description' => 'Aluguel',
            'bank_account_id' => $account->id,
        ]);

        $txId = $e->transactions()->first()?->id;
        $e->delete();

        $this->assertSoftDeleted('transactions', ['id' => $txId]);
        $this->assertEquals(5000.0, $account->fresh()->balance());
    }

    public function test_updating_distribution_updates_banking_income(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);
        $other = BankAccount::create(['name' => 'Outra', 'initial_balance' => 0]);
        $partnership = $this->makePartnership();

        $d = PartnershipDistribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-30',
            'amount' => 1000,
            'bank_account_id' => $account->id,
            'source' => 'Lucro Q1',
        ]);

        $d->update([
            'amount' => 2500,
            'bank_account_id' => $other->id,
            'source' => 'Lucro Q2',
        ]);

        $tx = $d->transactions()->first();
        $this->assertEquals(2500.0, (float) $tx->amount);
        $this->assertEquals($other->id, $tx->bank_account_id);
        $this->assertStringContainsString('Lucro Q2', $tx->description);
        $this->assertEquals(0.0, $account->fresh()->balance());
        $this->assertEquals(2500.0, $other->fresh()->balance());
    }

    public function test_deleting_distribution_removes_banking_income(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);
        $partnership = $this->makePartnership();

        $d = PartnershipDistribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-30',
            'amount' => 800,
            'bank_account_id' => $account->id,
        ]);

        $txId = $d->transactions()->first()?->id;
        $d->delete();

        $this->assertSoftDeleted('transactions', ['id' => $txId]);
        $this->assertEquals(0.0, $account->fresh()->balance());
    }

    public function test_deleting_partnership_removes_children_and_banking_transactions(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000]);
        $partnership = $this->makePartnership(30.0);

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-01-15',
            'amount' => 20000,
            'status' => 'done',
            'bank_account_id' => $account->id,
        ]);
        $e = PartnershipExpense::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-02-15',
            'total_amount' => 1000,
            'applied_percentage' => 30,
            'proportional_amount' => 300,
            'description' => 'Despesa',
            'bank_account_id' => $account->id,
        ]);
        $d = PartnershipDistribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-15',
            'amount' => 5000,
            'bank_account_id' => $account->id,
        ]);

        $txIds = [
            $c->transactions()->first()?->id,
            $e->transactions()->first()?->id,
            $d->transactions()->first()?->id,
        ];

        $this->assertEquals(84700.0, $account->fresh()->balance());

        app(PartnershipLedgerService::class)->deletePartnership($partnership);

        $this->assertSoftDeleted('partnerships', ['id' => $partnership->id]);
        $this->assertSoftDeleted('partnership_contributions', ['id' => $c->id]);
        $this->assertSoftDeleted('partnership_expenses', ['id' => $e->id]);
        $this->assertSoftDeleted('partnership_distributions', ['id' => $d->id]);
        foreach ($txIds as $txId) {
            $this->assertNotNull($txId);
            $this->assertSoftDeleted('transactions', ['id' => $txId]);
        }
        $this->assertEquals(100000.0, $account->fresh()->balance());
    }

    public function test_renaming_partnership_updates_transaction_descriptions(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 10000]);
        $partnership = $this->makePartnership();

        $c = PartnershipContribution::create([
            'partnership_id' => $partnership->id,
            'date' => '2026-04-10',
            'amount' => 1000,
            'status' => 'done',
            'bank_account_id' => $account->id,
        ]);

        $partnership->update(['name' => 'Novo Escritório']);

        $this->assertStringContainsString(
            'Novo Escritório',
            (string) $c->fresh()->transactions()->first()?->description
        );
    }
}

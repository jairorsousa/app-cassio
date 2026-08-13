<?php

namespace Tests\Unit\Brokers;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrokerBalanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_balances_for_reflects_open_advance_and_commission(): void
    {
        $broker = Broker::create(['name' => 'João Corretor']);
        $caseType = CaseType::create(['name' => 'Previdenciário']);

        BrokerAdvance::create([
            'broker_id' => $broker->id,
            'date' => Carbon::today(),
            'amount' => 400.00,
        ]);

        $commission = BrokerCommission::create([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'name' => 'Cliente',
            'base_amount' => 1000.00,
            'percentage_applied' => 0,
            'commission_amount' => 1000.00,
            'status' => 'pending',
            'reference_date' => Carbon::today(),
        ]);

        $advance = BrokerAdvance::create([
            'broker_id' => $broker->id,
            'date' => Carbon::today(),
            'amount' => 200.00,
        ]);

        BrokerCommissionSettlement::create([
            'commission_id' => $commission->id,
            'advance_id' => $advance->id,
            'amount_offset' => 200.00,
            'settled_at' => now(),
        ]);

        BrokerCommissionPayment::create([
            'broker_id' => $broker->id,
            'commission_id' => $commission->id,
            'paid_at' => Carbon::today(),
            'amount' => 150.00,
        ]);

        $balances = app(BrokerBalanceCalculator::class)->pendingBalancesFor([$broker->id]);

        $this->assertSame(400.00, $balances[$broker->id]['advance_pending']);
        $this->assertSame(650.00, $balances[$broker->id]['commission_pending']);
    }
}

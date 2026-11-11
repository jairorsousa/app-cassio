<?php

namespace Tests\Feature;

use App\Domains\Assets\Models\Property;
use App\Domains\Assets\Models\PropertyDownPayment;
use App\Domains\Assets\Models\PropertyFinancing;
use App\Domains\Assets\Models\PropertyFinancingInstallment;
use App\Domains\Assets\Models\PropertyPayment;
use App\Domains\Assets\Services\PropertyFinancingService;
use App\Domains\Assets\Services\PropertyPaymentService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Dashboard\Services\DashboardSnapshotBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PropertyFinancingTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bb;

    private BankAccount $bradesco;

    private Property $car;

    private Property $watch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->bb = BankAccount::create(['name' => 'Banco do Brasil', 'type' => 'checking', 'initial_balance' => 84927.38, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        $this->bradesco = BankAccount::create(['name' => 'Bradesco', 'type' => 'checking', 'initial_balance' => 29885.23, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        $this->car = Property::create([
            'kind' => 'veiculo', 'description' => 'Haval H6 GT', 'identifier' => 'SMV2E26',
            'acquired_at' => '2024-01-03', 'acquisition_value' => 321000, 'current_value' => 205920,
            'current_value_date' => '2026-11-06', 'owner' => 'cassio', 'status' => 'active',
        ]);
        $this->watch = Property::create([
            'kind' => 'outro', 'description' => 'Relógio Rolex', 'acquired_at' => '2026-10-30',
            'acquisition_value' => 70800, 'current_value' => 70800, 'status' => 'active', 'bank_account_id' => $this->bb->id,
        ]);
    }

    private function service(): PropertyFinancingService
    {
        return app(PropertyFinancingService::class);
    }

    private function setupHavalOpen(): PropertyFinancing
    {
        $this->service()->addDownPayment($this->car, [
            'status' => 'paid', 'date' => '2024-01-03', 'amount' => '221000', 'bank_account_id' => $this->bb->id, 'notes' => 'Entrada',
        ]);

        return $this->service()->createFinancing($this->car, [
            'creditor' => 'Bradesco', 'financed_amount' => '100000', 'installment_count' => 36,
            'installment_amount' => '2869.73', 'first_due_date' => '2025-01-28',
        ]);
    }

    public function test_generates_36_open_installments_on_the_28th(): void
    {
        $fin = $this->setupHavalOpen();
        $rows = $fin->installments;
        $this->assertCount(36, $rows);
        $this->assertTrue($rows->every(fn ($i) => $i->status === 'open' && $i->bank_account_id === null && $i->transaction_id === null));
        $this->assertSame('2025-01-28', $rows->first()->due_date->toDateString());
        $this->assertSame('2027-12-28', $rows->last()->due_date->toDateString());
        $this->assertTrue($rows->every(fn ($i) => $i->due_date->day === 28 && (float) $i->amount === 2869.73));
        $this->assertEquals(36, $rows->pluck('number')->unique()->count());
    }

    public function test_panel_numbers_and_debt_without_rate(): void
    {
        $this->setupHavalOpen();
        $s = $this->car->fresh(['downPayments', 'financings.installments', 'payments'])->financingSummary();
        $this->assertEquals(321000.0, $s['price']);
        $this->assertEquals(221000.0, $s['paid']);
        $this->assertEquals(103310.28, $s['remaining_debt']);
        $this->assertSame(36, $s['remaining_count']);
        $this->assertEquals(324310.28, $s['total_final']);
        $this->assertEquals(3310.28, $s['interest_cost']);
        $this->assertTrue($s['debt_includes_future_interest']);
    }

    public function test_historical_down_payment_does_not_touch_balance_or_create_transaction(): void
    {
        $before = $this->bb->balance();
        $down = $this->service()->addDownPayment($this->car, [
            'status' => 'paid', 'date' => '2024-01-03', 'amount' => '221000', 'bank_account_id' => $this->bb->id,
        ]);
        $this->assertInstanceOf(PropertyDownPayment::class, $down);
        $this->assertSame('paid', $down->status);
        $this->assertNull($down->transaction_id);
        $this->assertSame(0, Transaction::count());
        $this->assertEqualsWithDelta($before, $this->bb->fresh()->balance(), 0.001);
    }

    public function test_historical_installment_paid_does_not_touch_balance(): void
    {
        $fin = $this->setupHavalOpen();
        $beforeBb = $this->bb->balance();
        $beforeBradesco = $this->bradesco->balance();
        $first = $fin->installments->first();
        $paid = $this->service()->markInstallmentPaid($first, ['paid_at' => '2025-01-28', 'bank_account_id' => $this->bradesco->id]);
        $this->assertInstanceOf(PropertyFinancingInstallment::class, $paid);
        $this->assertSame('paid', $paid->status);
        $this->assertNull($paid->transaction_id);
        $this->assertSame(0, Transaction::count());
        $this->assertEqualsWithDelta($beforeBb, $this->bb->fresh()->balance(), 0.001);
        $this->assertEqualsWithDelta($beforeBradesco, $this->bradesco->fresh()->balance(), 0.001);
    }

    public function test_paid_after_initial_balance_creates_asset_purchase_out_of_result_and_lowers_balance(): void
    {
        $fin = $this->setupHavalOpen();
        $before = $this->bb->balance();
        $oct = $fin->installments->firstWhere('due_date', Carbon::parse('2026-10-28'));
        $this->assertNotNull($oct);
        $paid = $this->service()->markInstallmentPaid($oct, ['paid_at' => '2026-10-28', 'bank_account_id' => $this->bb->id]);
        $this->assertNotNull($paid->transaction_id);
        $tx = $paid->transaction;
        $this->assertSame('expense', $tx->type);
        $this->assertSame(Category::assetPurchaseCategoryId(), $tx->category_id);
        $this->assertSame(PropertyFinancingInstallment::class, $tx->source_type);
        $this->assertTrue($tx->isReadOnly());
        $this->assertEqualsWithDelta($before - 2869.73, $this->bb->fresh()->balance(), 0.001);
        $this->assertEquals(0.0, app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), 'accrual')['expense']);
        $this->assertEquals(0.0, app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), 'cash')['expense']);
    }

    public function test_mark_paid_without_account_is_just_history(): void
    {
        $fin = $this->setupHavalOpen();
        $before = $this->bb->balance();
        $paid = $this->service()->markInstallmentPaid($fin->installments->first(), ['paid_at' => '2026-10-28']);
        $this->assertSame('paid', $paid->status);
        $this->assertNull($paid->bank_account_id);
        $this->assertNull($paid->transaction_id);
        $this->assertSame(0, Transaction::count());
        $this->assertEqualsWithDelta($before, $this->bb->fresh()->balance(), 0.001);
    }

    public function test_duplicate_warning_and_link_does_not_create_second_transaction(): void
    {
        $fin = $this->setupHavalOpen();
        $other = Category::create(['name' => 'Veículos, Náutica & Transporte', 'type' => 'expense', 'status' => true]);
        $existing = Transaction::create([
            'type' => 'expense', 'status' => 'settled', 'date' => '2026-10-28', 'amount' => 2869.73,
            'description' => 'BOLETO HAVEL', 'bank_account_id' => $this->bb->id, 'category_id' => $other->id,
        ]);
        $before = $this->bb->fresh()->balance();
        $oct = $fin->installments->firstWhere('due_date', Carbon::parse('2026-10-28'));
        $result = $this->service()->markInstallmentPaid($oct, ['paid_at' => '2026-10-28', 'bank_account_id' => $this->bb->id]);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result['warnings']);
        $this->assertTrue($result['duplicates']->contains('id', $existing->id));
        $this->assertSame('open', $oct->fresh()->status);

        $paid = $this->service()->markInstallmentPaid($oct, ['paid_at' => '2026-10-28', 'bank_account_id' => $this->bb->id], confirmed: true, linkTransactionId: $existing->id);
        $this->assertSame('paid', $paid->status);
        $this->assertTrue($paid->linked_existing);
        $this->assertSame($existing->id, $paid->transaction_id);
        $this->assertSame(1, Transaction::count());
        $this->assertEqualsWithDelta($before, $this->bb->fresh()->balance(), 0.001);
        $this->assertSame(Category::assetPurchaseCategoryId(), $existing->fresh()->category_id);
        $this->assertEquals(0.0, app(OwnFinancialService::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'), 'accrual')['expense']);

        $this->service()->unpayInstallment($paid->fresh());
        $this->assertSame('open', $oct->fresh()->status);
        $this->assertNull($existing->fresh()->source_type);
        $this->assertSame($other->id, $existing->fresh()->category_id);
    }

    public function test_batch_mark_paid_with_different_accounts_and_dates(): void
    {
        $fin = $this->setupHavalOpen();
        $a = $fin->installments->firstWhere('number', 22); // 2026-10-28
        $b = $fin->installments->firstWhere('number', 23); // 2026-11-28
        $this->assertSame('2026-10-28', $a->due_date->toDateString());
        $beforeBb = $this->bb->balance();
        $beforeBradesco = $this->bradesco->balance();
        $done = $this->service()->markInstallmentsPaid([
            $a->id => ['paid_at' => '2026-10-28', 'bank_account_id' => $this->bb->id],
            $b->id => ['paid_at' => '2026-11-28', 'bank_account_id' => $this->bradesco->id],
        ], confirmed: true);
        $this->assertCount(2, $done);
        $this->assertEqualsWithDelta($beforeBb - 2869.73, $this->bb->fresh()->balance(), 0.001);
        $this->assertEqualsWithDelta($beforeBradesco - 2869.73, $this->bradesco->fresh()->balance(), 0.001);
        $this->assertSame(34, $this->car->fresh(['financings.installments'])->remainingDebt() > 0 ? $fin->fresh(['installments'])->remainingCount() : 0);
        $this->assertSame(34, $fin->fresh(['installments'])->remainingCount());
    }

    public function test_forecast_includes_open_future_installments(): void
    {
        $this->setupHavalOpen();
        Carbon::setTestNow('2026-11-11 12:00:00');
        $report = app(CashflowReportService::class)->forecast(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), []);
        $items = collect($report['items'])->where('source', 'Financiamento');
        $this->assertCount(1, $items);
        $this->assertEquals(2869.73, $items->first()['amount']);
        $this->assertSame('2026-11-28', $items->first()['date']);
        $this->assertStringContainsString('Haval H6 GT', $items->first()['description']);
        Carbon::setTestNow();
    }

    public function test_dashboard_subtracts_financing_debt_and_other_assets_stay(): void
    {
        $watchValue = $this->watch->patrimonyValue();
        $before = app(DashboardSnapshotBuilder::class)->build();
        $this->assertEquals(205920.0 + $watchValue, $before['patrimony']['properties']);
        $this->assertEquals(0.0, $before['patrimony']['properties_financing_debt'] ?? 0);

        $this->setupHavalOpen();
        $after = app(DashboardSnapshotBuilder::class)->build();
        $this->assertEquals(205920.0 + $watchValue, $after['patrimony']['properties'], 'bens continuam pelo valor atual');
        $this->assertEquals(103310.28, $after['patrimony']['properties_financing_debt']);
        $this->assertEqualsWithDelta($before['patrimony']['total'] - 103310.28, $after['patrimony']['total'], 0.001);
        $this->assertEquals(70800.0, $this->watch->fresh()->patrimonyValue());
    }

    public function test_rolex_payments_still_work_and_balances_for_unrelated_accounts_hold(): void
    {
        $this->setupHavalOpen();
        $beforeBradesco = $this->bradesco->balance();
        $pay = app(PropertyPaymentService::class)->record($this->watch, [
            'status' => 'paid', 'date' => '2026-10-30', 'amount' => '15800', 'method' => 'account',
            'bank_account_id' => $this->bb->id, 'notes' => 'Entrada',
        ]);
        $this->assertCount(1, $pay->transactions);
        $this->assertEquals(15800.0, $this->watch->fresh()->paymentSummary()['paid']);
        $this->assertEqualsWithDelta($beforeBradesco, $this->bradesco->fresh()->balance(), 0.001);
        $this->assertSame(1, PropertyPayment::count());
    }

    public function test_screen_marks_one_installment_paid(): void
    {
        $fin = $this->setupHavalOpen();
        $inst = $fin->installments->firstWhere('number', 22);
        Volt::test('bens.index')->call('openPayments', $this->car->id)
            ->assertSee('Forma de pagamento')
            ->assertSee('Haval H6 GT')
            ->assertSee('R$ 321.000,00')
            ->assertSee('R$ 3.310,28')
            ->assertSee('Em aberto')
            ->call('markInstallmentForm', $inst->id)
            ->set('instPaidAt', '2026-10-28')
            ->set('instAccount', (string) $this->bb->id)
            ->call('saveInstallmentPaid')
            ->assertHasNoErrors();
        $this->assertSame('paid', $inst->fresh()->status);
        $this->assertNotNull($inst->fresh()->transaction_id);
    }

    public function test_cannot_delete_property_with_paid_down_payment(): void
    {
        $this->setupHavalOpen();
        Volt::test('bens.index')->call('delete', $this->car->id);
        $this->assertNotSoftDeleted($this->car);
        Volt::test('bens.index')->call('delete', $this->watch->id);
        $this->assertSoftDeleted($this->watch);
    }
}

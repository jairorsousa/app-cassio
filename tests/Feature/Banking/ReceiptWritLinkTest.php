<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\ReceiptDestinationPayment;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\ReceiptDestinationService;
use App\Domains\Banking\Services\ReceiptWritLinkService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReceiptWritLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(15, 0));
        $this->actingAs(User::factory()->create());
    }

    private function example(string $writAmount = '7000.00'): array
    {
        $bank = BankAccount::create(['name' => 'CEF', 'initial_balance' => 4000]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse']);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $category = Category::create(['name' => 'Honorários próprios', 'type' => 'income', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'date' => '2026-10-08', 'amount' => '10000.00', 'status' => 'settled', 'bank_account_id' => $bank->id, 'description' => 'Maria', 'ofx_fitid' => 'original-statement']);
        app(ProcessReceiptService::class)->save($receipt, [
            'broker_id' => $broker->id, 'client_name' => 'Maria', 'fees' => '3000', 'fees_mode' => 'amount',
            'commission_mode' => 'amount', 'broker_amount' => '1000', 'office_amount' => '1000',
            'category_id' => $category->id, 'expenses' => [], 'advances' => [],
        ]);
        $writ = Writ::create(['type' => 'rpv', 'stage' => 'paid', 'assignor_name' => 'Maria', 'process_number' => '1007715-60.2023.4.01.3701', 'face_value' => 10000, 'paid_amount' => '4000.00', 'paid_at' => '2026-09-01', 'source_bank_account_id' => $bank->id]);
        WritMovedToPaid::dispatch($writ);
        $writ = app(WritService::class)->transitionTo($writ, 'finalized', ['finalized_at' => '2026-10-11', 'actual_receipt_amount' => $writAmount, 'destination_bank_account_id' => $bank->id]);
        $income = $writ->transactions()->where('type', 'income')->sole();
        $part = $receipt->destinations()->where('kind', 'client')->sole();

        return [$receipt, $part, $income, $writ, $bank, $client];
    }

    public function test_full_link_clears_client_and_keeps_a_single_bank_credit_without_paying_again(): void
    {
        [$receipt, $part, $income, $writ, $bank, $client] = $this->example();
        $this->assertSame(17000.0, $bank->fresh()->balance());
        Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('startPayment', $part->id)
            ->assertSee('Vincular a requisitório recebido')->set('mode', 'writ')->assertDontSee('Pagar pela conta')
            ->assertDontSee('Data do pagamento')->assertSee($writ->process_number)
            ->set('writIncomeId', $income->id)->set('bankId', null)->set('date', '')
            ->call('savePayment')->assertHasNoErrors()->assertSee('Requisitório adquirido')->assertSee('Baixado');
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame(0, $part->fresh()->remainingCents());
        $this->assertSame('0.00', $income->fresh()->amount);
        $this->assertSame('10000.00', $receipt->fresh()->amount);
        $this->assertSame('original-statement', $receipt->fresh()->ofx_fitid);
        $this->assertSame('7000.00', $writ->fresh()->actual_receipt_amount);
        $this->assertSame('4000.00', $writ->fresh()->paid_amount);
        $this->assertSame(3000.0, $writ->fresh()->actualProfit());
        $payment = $part->payments()->sole();
        $this->assertNull($payment->transaction_id);
        $this->assertSame('7000.00', $payment->writ_amount);
        $this->assertSame($client->id, $payment->settlementTransaction->bank_account_id);
        $this->assertSame('7000.00', $payment->settlementTransaction->amount);
        $this->assertSame(1, Transaction::where('type', 'expense')->count()); // Only the prior purchase.
        $this->assertSame(0, Transaction::where('type', 'transfer')->where('bank_account_id', $bank->id)->count());
        WritMovedToFinalized::dispatch($writ->fresh());
        $this->assertSame(1, $writ->transactions()->where('type', 'income')->count());
        $this->assertSame(10000.0, $bank->fresh()->balance());
        Volt::test('banking.transactions.index')->assertSee('Requisitórios adquiridos:')->assertSee('entrada restante');
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])->assertSee('Requisitório adquirido')->assertSee('Desvincular requisitório');
        $this->get(route('writs.show', $writ))->assertOk()->assertSee('vinculados a recebimentos de processo');
    }

    public function test_partial_links_and_cash_payments_share_the_same_remaining_client_balance(): void
    {
        [, $part, $income, , $bank, $client] = $this->example();
        $service = app(ReceiptWritLinkService::class);
        $service->link($part, $income->id, '2000.25');
        $this->assertSame('4999.75', $income->fresh()->amount);
        $this->assertSame(-4999.75, $client->fresh()->balance());
        app(ReceiptDestinationService::class)->pay($part, '1000', now()->toDateString(), $bank->id);
        $service->link($part, $income->id, '3999.75');
        $this->assertSame(0, $part->fresh()->remainingCents());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertSame('1000.00', $income->fresh()->amount);
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 3);
    }

    public function test_link_cannot_exceed_either_the_client_or_writ_remaining_amount(): void
    {
        [, $part, $income, , $bank, $client] = $this->example('5000.00');
        $service = app(ReceiptWritLinkService::class);
        foreach (['7000.01', '5000.01', '0', '-1', '1.001'] as $amount) {
            try {
                $service->link($part, $income->id, $amount);
                $this->fail('Invalid amount accepted');
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $service->link($part, $income->id, '5000');
        $this->assertSame(-2000.0, $client->fresh()->balance());
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame('0.00', $income->fresh()->amount);
        try {
            $service->link($part, $income->id, '1');
            $this->fail('Exhausted Writ accepted');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('saldo disponível', $e->getMessage());
        }
    }

    public function test_sources_must_be_received_purchased_writs_in_the_same_bank_and_client_only(): void
    {
        [$receipt, $part, $income, $writ, $bank] = $this->example();
        $other = BankAccount::create(['name' => 'Outro banco']);
        foreach ([['source_type' => null], ['bank_account_id' => $other->id], ['status' => 'pending'], ['date' => now()->addDay()->toDateString()], ['type' => 'expense']] as $patch) {
            $old = $income->only(array_keys($patch));
            $income->update($patch);
            $this->assertSame(0, app(ReceiptWritLinkService::class)->availableIncomes($part)->count());
            try {
                app(ReceiptWritLinkService::class)->link($part, $income->id, '1');
                $this->fail('Invalid source accepted');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('requisitório comprado e recebido', $e->getMessage());
            }
            $income->update($old);
        }
        foreach ([['stage' => 'awaiting_receipt'], ['paid_amount' => '0']] as $patch) {
            $old = $writ->only(array_keys($patch));
            $writ->update($patch);
            $this->assertSame(0, app(ReceiptWritLinkService::class)->availableIncomes($part)->count());
            $writ->update($old);
        }
        $office = $receipt->destinations()->where('kind', 'office')->sole();
        Volt::test('banking.accounts.repasses', ['accountId' => $office->repasse_account_id])->call('startPayment', $office->id)->assertDontSee('Vincular a requisitório recebido')
            ->set('mode', 'writ')->set('writIncomeId', $income->id)->set('amount', '100')->call('savePayment')->assertHasErrors('payment');
        $this->assertSame(17000.0, $bank->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
    }

    public function test_undo_restores_exact_income_and_client_balance_without_modifying_purchase_or_receipt(): void
    {
        [$receipt, $part, $income, $writ, $bank, $client] = $this->example();
        $service = app(ReceiptWritLinkService::class);
        $service->link($part, $income->id, '2500.01');
        $first = $part->payments()->sole();
        $entry = $first->settlementTransaction;
        $service->link($part, $income->id, '100.02');
        Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('undoPayment', $first->id)->assertHasNoErrors();
        $this->assertSame('6899.98', $income->fresh()->amount);
        $this->assertSame(-6899.98, $client->fresh()->balance());
        $this->assertSame(16899.98, $bank->fresh()->balance());
        $this->assertSoftDeleted($entry);
        $this->assertSame('7000.00', $writ->fresh()->actual_receipt_amount);
        $this->assertSame('10000.00', $receipt->fresh()->amount);
        app(ReceiptDestinationService::class)->undo($part->payments()->sole());
        $this->assertSame('7000.00', $income->fresh()->amount);
        $this->assertSame(-7000.0, $client->fresh()->balance());
    }

    public function test_deleting_original_receipt_releases_writ_links_but_preserves_purchase_and_writ_income(): void
    {
        [$receipt, $part, $income, $writ, $bank, $client] = $this->example();
        app(ReceiptWritLinkService::class)->link($part, $income->id, '7000');
        app(TransactionService::class)->deleteWithDestinations($receipt);
        $this->assertNotNull($writ->fresh());
        $this->assertSame('7000.00', $income->fresh()->amount);
        $this->assertSame(7000.0, $bank->fresh()->balance());
        $this->assertSame(0.0, $client->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
        $this->assertSame(2, $writ->transactions()->count());
    }

    public function test_linked_writ_cannot_be_deleted_or_its_receipt_changed_until_unlinked(): void
    {
        [, $part, $income, $writ] = $this->example();
        app(ReceiptWritLinkService::class)->link($part, $income->id, '100');
        foreach ([fn () => app(WritService::class)->delete($writ), fn () => $writ->fresh()->forceDelete(),
            fn () => $writ->fresh()->update(['actual_receipt_amount' => 8000]),
            fn () => app(WritService::class)->transitionTo($writ->fresh(), 'awaiting_receipt', ['awaiting_receipt_at' => now()]),
            fn () => app(TransactionService::class)->updateGenerated($income->fresh(), ['amount' => 8000]),
            fn () => app(TransactionService::class)->deleteGenerated($income->fresh()),
        ] as $action) {
            try {
                $action();
                $this->fail('Linked Writ must be protected');
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Desvincule', $e->getMessage());
            }
            $this->assertNotNull($writ->fresh());
            $this->assertSame(2, $writ->transactions()->count());
        }
        Volt::test('writs.kanban')->call('delete', $writ->id);
        $this->assertNotNull($writ->fresh());
        Volt::test('writs.show', ['writ' => $writ])->call('delete');
        $this->assertNotNull($writ->fresh());
        app(ReceiptDestinationService::class)->undo($part->payments()->sole());
        app(WritService::class)->delete($writ->fresh());
        $this->assertNull($writ->fresh());
    }

    public function test_linked_receipt_division_cannot_be_cleared_until_unlinked(): void
    {
        [$receipt, $part, $income] = $this->example();
        app(ReceiptWritLinkService::class)->link($part, $income->id, '1');
        $this->expectException(\DomainException::class);
        app(ReceiptDestinationService::class)->clear($receipt);
    }

    public function test_idempotent_retries_and_stale_forms_cannot_reuse_writ_balance(): void
    {
        [, $part, $income, , $bank] = $this->example();
        $service = app(ReceiptWritLinkService::class);
        $key = (string) Str::uuid();
        $service->link($part, $income->id, '4000', $key);
        $service->link($part, $income->id, '4000', $key);
        $this->assertDatabaseCount('receipt_destination_payments', 1);
        $this->assertSame('3000.00', $income->fresh()->amount);
        $this->assertSame(13000.0, $bank->fresh()->balance());
        try {
            $service->link($part, $income->id, '4000', (string) Str::uuid());
            $this->fail('Stale form must respect remaining amounts');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('saldo do cliente', $e->getMessage());
        }
    }

    public function test_failure_to_credit_virtual_account_rolls_back_income_adjustment_and_link(): void
    {
        [, $part, $income, , $bank, $client] = $this->example();
        $dispatcher = Transaction::getEventDispatcher();
        Transaction::setEventDispatcher(clone $dispatcher);
        Transaction::creating(function (Transaction $transaction) use ($client) {
            if ($transaction->bank_account_id === $client->id && $transaction->source_type === ReceiptDestinationPayment::class) {
                throw new \RuntimeException('Falha na baixa');
            }
        });
        try {
            app(ReceiptWritLinkService::class)->link($part, $income->id, '500');
            $this->fail('Failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Falha na baixa', $e->getMessage());
        } finally {
            Transaction::setEventDispatcher($dispatcher);
        }
        $this->assertSame('7000.00', $income->fresh()->amount);
        $this->assertSame(17000.0, $bank->fresh()->balance());
        $this->assertSame(-7000.0, $client->fresh()->balance());
        $this->assertDatabaseCount('receipt_destination_payments', 0);
    }

    public function test_reports_count_received_cash_once_and_classify_acquired_amount_as_own_receipt(): void
    {
        [$receipt, $part, $income, $writ, $bank] = $this->example();
        $before = Volt::test('banking.reports.cashflow')->set('month', '2026-10');
        $this->assertEquals(17000, $before->viewData('totalIncome'));
        app(ReceiptWritLinkService::class)->link($part, $income->id, '7000');
        $report = Volt::test('banking.reports.cashflow')->set('month', '2026-10');
        $this->assertEquals(10000, $report->viewData('totalIncome'));
        $this->assertEquals(0, $report->viewData('totalExpense'));
        $this->assertEquals(8000, $report->viewData('ownIncome'));
        $this->assertEquals(2000, $report->viewData('thirdPartyIncome'));
        $this->assertEquals(7000, $report->viewData('byCategory')->get('Requisitórios adquiridos')['income']);
        $this->assertEquals(1000, $report->viewData('byCategory')->get('Honorários próprios')['income']);
        $this->assertEquals(['in' => 10000, 'out' => 0], $report->viewData('byAccount')->get('CEF'));
        Livewire::withoutLazyLoading();
        $this->assertEquals(8000, Volt::test('banking.dashboard')->viewData('monthIncome'));
        $this->assertSame(3000.0, $writ->fresh()->actualProfit());
    }

    public function test_previous_obligation_migration_remains_usable_before_writ_link_columns_exist(): void
    {
        [, , , , $bank, $client] = $this->example();
        $newMigration = require database_path('migrations/2026_10_20_050000_add_writ_links_to_receipt_payments.php');
        $oldMigration = require database_path('migrations/2026_10_20_040000_convert_repasse_balances_to_obligations.php');
        $newMigration->down();
        try {
            $oldMigration->up();
            $this->assertSame(17000.0, $bank->fresh()->balance());
            $this->assertSame(-7000.0, $client->fresh()->balance());
        } finally {
            $newMigration->up();
        }
    }
}

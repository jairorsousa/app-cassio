<?php

namespace Tests\Feature\Writs;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use App\Domains\Writs\Services\WritService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WritPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeWrit(array $overrides = []): Writ
    {
        return Writ::create(array_merge([
            'type' => 'rpv',
            'stage' => 'negotiation',
            'process_number' => '0001234-56.2026.8.13.0001',
            'court' => 'Vara Federal de BH',
            'debtor_entity' => 'INSS',
            'credit_nature' => 'alimentar',
            'assignor_name' => 'João da Silva',
            'assignor_document' => '123.456.789-00',
            'face_value' => 100000.00,
            'paid_amount' => 60000.00,
            'discount_percentage' => 40.000,
            'estimated_receipt_amount' => 100000.00,
            'estimated_months' => 12,
        ], $overrides));
    }

    // ──────────────────────────────────────────────
    // 1. Card percorre as 6 etapas e gera transactions corretas
    // ──────────────────────────────────────────────

    public function test_writ_traverses_all_six_stages(): void
    {
        $account = BankAccount::create(['name' => 'Conta Principal', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->makeWrit([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
        ]);

        $service = app(WritService::class);

        // negotiation → pending
        $writ = $service->transitionTo($writ, 'pending');
        $this->assertEquals('pending', $writ->stage);

        // pending → paid
        $writ = $service->transitionTo($writ, 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);
        $this->assertEquals('paid', $writ->stage);
        $this->assertEquals('2026-04-01', $writ->paid_at->format('Y-m-d'));

        // paid → petitioning
        $writ = $service->transitionTo($writ, 'petitioning');
        $this->assertEquals('petitioning', $writ->stage);

        // petitioning → awaiting_receipt
        $writ = $service->transitionTo($writ, 'awaiting_receipt');
        $this->assertEquals('awaiting_receipt', $writ->stage);

        // awaiting_receipt → finalized
        $writ = $service->transitionTo($writ, 'finalized', [
            'finalized_at' => '2026-10-01',
            'actual_receipt_amount' => 98000.00,
            'destination_bank_account_id' => $account->id,
        ]);
        $this->assertEquals('finalized', $writ->stage);
        $this->assertEquals('2026-10-01', $writ->finalized_at->format('Y-m-d'));
        $this->assertEquals(98000.00, (float) $writ->actual_receipt_amount);
    }

    public function test_paid_stage_creates_expense_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->makeWrit(['source_bank_account_id' => $account->id]);

        $service = app(WritService::class);
        $service->transitionTo($writ, 'pending');
        $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);

        // Deve ter criado 1 transaction de despesa vinculada polimorficamente
        $transactions = Transaction::where('source_type', Writ::class)
            ->where('source_id', $writ->id)
            ->where('type', 'expense')
            ->get();

        $this->assertCount(1, $transactions);
        $this->assertEquals(60000.00, (float) $transactions->first()->amount);
        $this->assertEquals('settled', $transactions->first()->status);
        $this->assertEquals($account->id, $transactions->first()->bank_account_id);
    }

    public function test_pending_stage_stores_cession_datetime(): void
    {
        $writ = $this->makeWrit();

        $updated = app(WritService::class)->transitionTo($writ, 'pending', [
            'cession_at' => '2026-06-15 14:30:00',
        ]);

        $this->assertEquals('pending', $updated->stage);
        $this->assertEquals('2026-06-15 14:30:00', $updated->cession_at->format('Y-m-d H:i:s'));
    }

    public function test_awaiting_receipt_stage_stores_datetime(): void
    {
        $writ = $this->makeWrit(['stage' => 'petitioning']);

        $updated = app(WritService::class)->transitionTo($writ, 'awaiting_receipt', [
            'awaiting_receipt_at' => '2026-06-23 16:00:00',
        ]);

        $this->assertEquals('awaiting_receipt', $updated->stage);
        $this->assertEquals('2026-06-23 16:00:00', $updated->awaiting_receipt_at->format('Y-m-d H:i:s'));
    }

    public function test_finalized_stage_creates_income_transaction(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->makeWrit([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
        ]);

        $service = app(WritService::class);
        $service->transitionTo($writ, 'pending');
        $writ = $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);
        $service->transitionTo($writ->fresh(), 'petitioning');
        $service->transitionTo($writ->fresh(), 'awaiting_receipt');
        $service->transitionTo($writ->fresh(), 'finalized', [
            'finalized_at' => '2026-10-01',
            'actual_receipt_amount' => 98000.00,
            'destination_bank_account_id' => $account->id,
        ]);

        // Deve ter criado 1 transaction de receita
        $incomes = Transaction::where('source_type', Writ::class)
            ->where('source_id', $writ->id)
            ->where('type', 'income')
            ->get();

        $this->assertCount(1, $incomes);
        $this->assertEquals(98000.00, (float) $incomes->first()->amount);
        $this->assertEquals($account->id, $incomes->first()->bank_account_id);
    }

    public function test_full_pipeline_creates_both_expense_and_income(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 200000, 'status' => true]);
        $writ = $this->makeWrit([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
        ]);

        $service = app(WritService::class);

        // Pipeline completo
        $writ = $service->transitionTo($writ, 'pending');
        $writ = $service->transitionTo($writ, 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);
        $writ = $service->transitionTo($writ, 'petitioning');
        $writ = $service->transitionTo($writ, 'awaiting_receipt');
        $writ = $service->transitionTo($writ, 'finalized', [
            'finalized_at' => '2026-10-01',
            'actual_receipt_amount' => 98000.00,
            'destination_bank_account_id' => $account->id,
        ]);

        // 2 transactions no total: 1 despesa (paid) + 1 receita (finalized)
        $writTransactions = Transaction::where('source_type', Writ::class)
            ->where('source_id', $writ->id)
            ->get();

        $this->assertCount(2, $writTransactions);
        $this->assertCount(1, $writTransactions->where('type', 'expense'));
        $this->assertCount(1, $writTransactions->where('type', 'income'));

        // Saldo da conta: 200000 - 60000 + 98000 = 238000
        $this->assertEquals(238000.00, $account->fresh()->balance());
    }

    public function test_duplicate_paid_event_does_not_create_second_expense(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->makeWrit(['source_bank_account_id' => $account->id]);

        $service = app(WritService::class);
        $service->transitionTo($writ, 'pending');
        $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);

        // Regredir e pagar novamente
        $service->transitionTo($writ->fresh(), 'pending');
        $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-04-02',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);

        $expenses = Transaction::where('source_type', Writ::class)
            ->where('source_id', $writ->id)
            ->where('type', 'expense')
            ->count();

        // Listener tem guarda de idempotência — não deve duplicar
        $this->assertEquals(1, $expenses);
    }

    // ──────────────────────────────────────────────
    // 2. Rentabilidade real é calculada corretamente
    // ──────────────────────────────────────────────

    public function test_profitability_calculator_computes_correct_values(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'actual_receipt_amount' => 98000.00,
            'paid_at' => '2026-04-01',
            'finalized_at' => '2026-10-01',
        ]);

        $calc = app(WritProfitabilityCalculator::class);
        $result = $calc->realized($writ);

        // Lucro: 98000 - 60000 = 38000
        $this->assertEquals(38000.00, $result['profit_amount']);

        // %: (38000 / 60000) * 100 = 63.333%
        $this->assertEqualsWithDelta(63.333, $result['profit_percentage'], 0.01);

        // Dias decorridos: 2026-04-01 a 2026-10-01 = 183 dias
        $this->assertEquals(183, $result['days_elapsed']);

        // % ao mês deve ser > 0
        $this->assertNotNull($result['monthly_rate']);
        $this->assertGreaterThan(0, $result['monthly_rate']);
    }

    public function test_profitability_with_zero_paid(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 0,
            'actual_receipt_amount' => 5000.00,
        ]);

        $calc = app(WritProfitabilityCalculator::class);
        $result = $calc->realized($writ);

        $this->assertEquals(5000.00, $result['profit_amount']);
        $this->assertEquals(0.0, $result['profit_percentage']);
    }

    public function test_profitability_without_receipt_returns_zero_profit(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'actual_receipt_amount' => null,
        ]);

        $calc = app(WritProfitabilityCalculator::class);
        $result = $calc->realized($writ);

        $this->assertEquals(-60000.00, $result['profit_amount']);
        $this->assertNull($result['days_elapsed']);
        $this->assertNull($result['monthly_rate']);
    }

    public function test_profitability_with_loss(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'actual_receipt_amount' => 50000.00,
            'paid_at' => '2026-01-01',
            'finalized_at' => '2026-07-01',
        ]);

        $calc = app(WritProfitabilityCalculator::class);
        $result = $calc->realized($writ);

        $this->assertEquals(-10000.00, $result['profit_amount']);
        $this->assertLessThan(0, $result['profit_percentage']);
    }

    // ──────────────────────────────────────────────
    // 3. Histórico de transições é persistido
    // ──────────────────────────────────────────────

    public function test_transition_history_is_persisted(): void
    {
        $writ = $this->makeWrit();
        $service = app(WritService::class);

        $service->transitionTo($writ, 'pending', ['notes' => 'Documentos recebidos']);
        $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-05-01',
            'paid_amount' => 60000.00,
            'notes' => 'Pagamento via TED',
        ]);

        $history = WritStageHistory::where('writ_id', $writ->id)
            ->orderBy('transitioned_at')
            ->get();

        $this->assertCount(2, $history);

        // 1ª transição: negotiation → pending
        $this->assertEquals('negotiation', $history[0]->from_stage);
        $this->assertEquals('pending', $history[0]->to_stage);
        $this->assertEquals('Documentos recebidos', $history[0]->notes);

        // 2ª transição: pending → paid
        $this->assertEquals('pending', $history[1]->from_stage);
        $this->assertEquals('paid', $history[1]->to_stage);
        $this->assertEquals('Pagamento via TED', $history[1]->notes);
    }

    public function test_history_includes_all_transitions_in_full_pipeline(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 200000, 'status' => true]);
        $writ = $this->makeWrit([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
        ]);

        $service = app(WritService::class);

        $writ = $service->transitionTo($writ, 'pending');
        $writ = $service->transitionTo($writ, 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
        ]);
        $writ = $service->transitionTo($writ, 'petitioning');
        $writ = $service->transitionTo($writ, 'awaiting_receipt');
        $writ = $service->transitionTo($writ, 'finalized', [
            'finalized_at' => '2026-10-01',
            'actual_receipt_amount' => 98000.00,
        ]);

        $history = WritStageHistory::where('writ_id', $writ->id)->get();
        $this->assertCount(5, $history);

        $expectedFlow = [
            ['negotiation', 'pending'],
            ['pending', 'paid'],
            ['paid', 'petitioning'],
            ['petitioning', 'awaiting_receipt'],
            ['awaiting_receipt', 'finalized'],
        ];

        foreach ($expectedFlow as $i => [$from, $to]) {
            $this->assertEquals($from, $history[$i]->from_stage, "Transição {$i}: from_stage incorreto");
            $this->assertEquals($to, $history[$i]->to_stage, "Transição {$i}: to_stage incorreto");
        }
    }

    // ──────────────────────────────────────────────
    // 4. Validação de transições
    // ──────────────────────────────────────────────

    public function test_invalid_transition_throws_domain_exception(): void
    {
        $writ = $this->makeWrit();
        $service = app(WritService::class);

        // negotiation → finalized não é permitido
        $this->expectException(\DomainException::class);
        $service->transitionTo($writ, 'finalized');
    }

    public function test_invalid_stage_throws_invalid_argument(): void
    {
        $writ = $this->makeWrit();
        $service = app(WritService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->transitionTo($writ, 'nonexistent_stage');
    }

    public function test_same_stage_is_noop(): void
    {
        $writ = $this->makeWrit();
        $service = app(WritService::class);

        $result = $service->transitionTo($writ, 'negotiation');
        $this->assertEquals('negotiation', $result->stage);

        // Nenhum histórico criado
        $this->assertCount(0, WritStageHistory::where('writ_id', $writ->id)->get());
    }

    public function test_regression_transition_is_allowed(): void
    {
        $writ = $this->makeWrit();
        $service = app(WritService::class);

        $writ = $service->transitionTo($writ, 'pending');
        $this->assertEquals('pending', $writ->stage);

        // Regredir: pending → negotiation
        $writ = $service->transitionTo($writ, 'negotiation');
        $this->assertEquals('negotiation', $writ->stage);

        $history = WritStageHistory::where('writ_id', $writ->id)->get();
        $this->assertCount(2, $history);
    }

    // ──────────────────────────────────────────────
    // 5. Model helpers
    // ──────────────────────────────────────────────

    public function test_writ_model_discount_calculation(): void
    {
        $writ = $this->makeWrit([
            'face_value' => 100000.00,
            'paid_amount' => 60000.00,
        ]);

        // (1 - 60000/100000) * 100 = 40%
        $this->assertEquals(40.0, $writ->discountPercentageCalculated());
    }

    public function test_writ_model_discount_uses_negotiated_amount_when_present(): void
    {
        $writ = $this->makeWrit([
            'face_value' => 100000.00,
            'negotiated_amount' => 70000.00,
            'paid_amount' => 49000.00,
        ]);

        // (1 - 49000/70000) * 100 = 30%
        $this->assertEquals(30.0, $writ->discountPercentageCalculated());
    }

    public function test_writ_model_estimated_profit(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'estimated_receipt_amount' => 100000.00,
        ]);

        $this->assertEquals(40000.00, $writ->estimatedProfit());
    }

    public function test_writ_model_actual_profit(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'actual_receipt_amount' => 98000.00,
        ]);

        $this->assertEquals(38000.00, $writ->actualProfit());
    }

    public function test_writ_model_actual_profit_returns_null_without_receipt(): void
    {
        $writ = $this->makeWrit([
            'paid_amount' => 60000.00,
            'actual_receipt_amount' => null,
        ]);

        $this->assertNull($writ->actualProfit());
    }

    public function test_transactions_are_linked_polymorphically(): void
    {
        $account = BankAccount::create(['name' => 'Conta', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->makeWrit(['source_bank_account_id' => $account->id]);

        $service = app(WritService::class);
        $service->transitionTo($writ, 'pending');
        $service->transitionTo($writ->fresh(), 'paid', [
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000.00,
            'source_bank_account_id' => $account->id,
        ]);

        // Testar a relação morphMany no model
        $linkedTransactions = $writ->fresh()->transactions;
        $this->assertCount(1, $linkedTransactions);
        $this->assertTrue($linkedTransactions->first()->isReadOnly());
    }
}

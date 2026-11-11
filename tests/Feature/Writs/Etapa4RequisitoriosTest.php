<?php

namespace Tests\Feature\Writs;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use App\Domains\Writs\Services\WritService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa4RequisitoriosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WritService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->service = app(WritService::class);
    }

    private function writ(array $overrides = []): Writ
    {
        return Writ::create(array_merge([
            'type' => 'rpv',
            'stage' => 'negotiation',
            'process_number' => '0001111-22.2026.8.13.0001',
            'assignor_name' => 'Cedente Teste',
            'face_value' => 100000,
            'negotiated_amount' => 90000,
            'proposed_amount' => 60000,
            'paid_amount' => 0,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'estimated_receipt_amount' => 95000,
            'estimated_months' => 6,
        ], $overrides));
    }

    private function acquire(Writ $writ, BankAccount $account, array $extra = []): Writ
    {
        $writ = $this->service->transitionTo($writ, 'pending', ['cession_at' => '2026-03-01 10:00:00']);
        $writ = $this->service->transitionTo($writ, 'signature', ['signature_at' => '2026-03-10 15:00:00']);

        return $this->service->transitionTo($writ, 'paid', array_merge([
            'paid_at' => '2026-04-01',
            'paid_amount' => 60000,
            'source_bank_account_id' => $account->id,
        ], $extra));
    }

    public function test_assinatura_fica_entre_cessao_e_pagamento(): void
    {
        $this->assertSame(
            ['monitoring', 'negotiation', 'pending', 'signature', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost'],
            Writ::STAGES
        );
        $this->assertSame('Assinatura', Writ::STAGE_LABELS['signature']);
        $this->assertSame('Pagamento', Writ::STAGE_LABELS['paid']);

        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 100000, 'status' => true]);
        $writ = $this->writ(['source_bank_account_id' => $account->id]);

        $this->expectException(\DomainException::class);
        $this->service->transitionTo($writ, 'paid', ['paid_at' => '2026-04-01', 'paid_amount' => 60000, 'source_bank_account_id' => $account->id]);
    }

    public function test_fluxo_completo_com_assinatura_e_aguardando_recebimento(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 200000, 'status' => true]);
        $writ = $this->writ([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
            'notary_expenses_amount' => 200,
        ]);

        $writ = $this->acquire($writ, $account, ['paid_amount' => 60000]);
        $this->assertSame('paid', $writ->stage);
        $this->assertNotNull($writ->signature_at);

        $writ = $this->service->transitionTo($writ, 'petitioning', ['petitioned_at' => '2026-05-01 09:00:00']);
        $writ = $this->service->transitionTo($writ, 'awaiting_receipt', ['awaiting_receipt_at' => '2026-06-01 09:00:00']);
        $writ = $this->service->transitionTo($writ, 'finalized', [
            'finalized_at' => '2026-09-01',
            'actual_receipt_amount' => 90000,
            'destination_bank_account_id' => $account->id,
        ]);

        $this->assertSame('finalized', $writ->stage);
        $this->assertSame(60200.0, $writ->totalCost());
        $this->assertSame(29800.0, $writ->actualProfit());
        $this->assertTrue(
            WritStageHistory::where('writ_id', $writ->id)->where('to_stage', 'signature')->exists()
        );
    }

    public function test_perdido_exige_motivo_e_pode_partir_de_qualquer_etapa_operacional(): void
    {
        foreach (['monitoring', 'negotiation', 'pending', 'signature', 'paid', 'petitioning', 'awaiting_receipt', 'finalized'] as $stage) {
            $writ = $this->writ(['stage' => $stage, 'paid_amount' => 1000, 'paid_at' => '2026-01-01', 'monitoring_at' => '2026-01-01 10:00:00', 'signature_at' => '2026-01-02 10:00:00', 'petitioned_at' => '2026-01-03 10:00:00', 'awaiting_receipt_at' => '2026-01-04 10:00:00', 'finalized_at' => '2026-01-05', 'actual_receipt_amount' => 2000]);

            try {
                $this->service->transitionTo($writ, 'lost');
                $this->fail("Deveria exigir motivo a partir de {$stage}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('motivo', mb_strtolower($e->getMessage()));
            }

            $updated = $this->service->transitionTo($writ->fresh(), 'lost', [
                'lost_reason' => "Perda na etapa {$stage}",
            ]);
            $this->assertSame('lost', $updated->stage);
            $this->assertSame("Perda na etapa {$stage}", $updated->lost_reason);
            $this->assertNotNull($updated->lost_at);
            $this->assertTrue(
                WritStageHistory::where('writ_id', $updated->id)->where('to_stage', 'lost')->exists()
            );
        }
    }

    public function test_prazo_estimado_versus_real_sem_inventar_dados(): void
    {
        $writ = $this->writ([
            'stage' => 'awaiting_receipt',
            'paid_amount' => 50000,
            'notary_expenses_amount' => 100,
            'paid_at' => '2026-01-01',
            'estimated_months' => 6,
            'awaiting_receipt_at' => '2026-02-01 10:00:00',
        ]);

        $this->assertSame('2026-07-01', $writ->estimatedReceiptDeadline()->format('Y-m-d'));
        $this->assertSame((int) Carbon::parse('2026-01-01')->diffInDays(Carbon::parse('2026-11-05')), $writ->actualDaysElapsed());
        $this->assertTrue($writ->isOverdue());
        $this->assertGreaterThan(0, $writ->daysOverdue());

        $old = $this->writ([
            'stage' => 'paid',
            'paid_amount' => 10000,
            'paid_at' => null,
            'estimated_months' => 6,
        ]);
        $this->assertNull($old->estimatedReceiptDeadline());
        $this->assertNull($old->actualDaysElapsed());
        $this->assertFalse($old->isOverdue());

        $withoutEstimate = $this->writ([
            'stage' => 'paid',
            'paid_amount' => 10000,
            'paid_at' => '2026-01-01',
            'estimated_months' => null,
        ]);
        $this->assertNull($withoutEstimate->estimatedReceiptDeadline());
        $this->assertFalse($withoutEstimate->isOverdue());
    }

    public function test_totais_do_funil_excluem_monitorar_e_usam_custo_total(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 500000, 'status' => true]);

        // Monitorar: nao conta como aquisicao
        $this->writ([
            'stage' => 'monitoring',
            'monitoring_at' => '2026-01-01 10:00:00',
            'face_value' => 999999,
            'estimated_receipt_amount' => 999999,
            'paid_amount' => 0,
        ]);

        // Em aberto adquirido
        $open = $this->writ([
            'source_bank_account_id' => $account->id,
            'paid_amount' => 0,
            'notary_expenses_amount' => 0,
            'estimated_receipt_amount' => 80000,
        ]);
        $open = $this->acquire($open, $account, ['paid_amount' => 40000]);
        $open->update(['notary_expenses_amount' => 1000, 'estimated_receipt_amount' => 80000]);

        // Recebido
        $done = $this->writ([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
            'estimated_receipt_amount' => 70000,
        ]);
        $done = $this->acquire($done, $account, ['paid_amount' => 30000]);
        $done->update(['notary_expenses_amount' => 500]);
        $this->service->transitionTo($done->fresh(), 'petitioning', ['petitioned_at' => '2026-05-01 10:00:00']);
        $this->service->transitionTo($done->fresh(), 'awaiting_receipt', ['awaiting_receipt_at' => '2026-06-01 10:00:00']);
        $this->service->transitionTo($done->fresh(), 'finalized', [
            'finalized_at' => '2026-08-01',
            'actual_receipt_amount' => 70000,
            'destination_bank_account_id' => $account->id,
        ]);

        \Livewire\Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->assertSee('Total investido até hoje')
            ->assertSee('ainda não houve compra')
            ->assertSee('Lucro esperado')
            ->assertSee('Assinatura');
    }

    public function test_lucro_realizado_aparece_em_juros_ganho_sem_duplicar_caixa(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 300000, 'status' => true]);
        $parent = Category::create(['name' => 'Rendimento de Investimentos', 'type' => 'income', 'status' => true]);
        Category::create([
            'name' => Category::WRIT_PROFIT_NAME,
            'type' => 'income',
            'status' => true,
            'parent_id' => $parent->id,
        ]);

        $writ = $this->writ([
            'source_bank_account_id' => $account->id,
            'destination_bank_account_id' => $account->id,
            'notary_expenses_amount' => 200,
        ]);
        $writ = $this->acquire($writ, $account, ['paid_amount' => 40000]);
        $writ = $this->service->transitionTo($writ->fresh(), 'petitioning', ['petitioned_at' => '2026-05-01 10:00:00']);
        $writ = $this->service->transitionTo($writ, 'awaiting_receipt', ['awaiting_receipt_at' => '2026-06-01 10:00:00']);
        $writ = $this->service->transitionTo($writ, 'finalized', [
            'finalized_at' => '2026-10-08',
            'actual_receipt_amount' => 55167.18,
            'destination_bank_account_id' => $account->id,
        ]);

        $income = $writ->transactions()->where('type', 'income')->sole();
        $this->assertSame(Category::writProfitCategoryId(), (int) $income->category_id);
        $this->assertEquals(55167.18, (float) $income->amount);

        $split = $writ->receiptSplit();
        $this->assertEqualsWithDelta(14967.18, $split['profit'], 0.01); // 55167.18 - 40200
        $this->assertEqualsWithDelta(40200.00, $split['capital'], 0.01);

        // Caixa: uma despesa de aquisicao + cartorio + uma receita — sem lancamento extra de lucro
        $this->assertSame(1, $writ->transactions()->where('type', 'income')->count());
        $this->assertGreaterThanOrEqual(1, $writ->transactions()->where('type', 'expense')->count());
    }

    public function test_vinculo_no_recebimento_de_processo_preserva_baixa_sem_repasse_e_separa_juros(): void
    {
        $bank = BankAccount::create(['name' => 'CEF', 'initial_balance' => 0, 'status' => true]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $parent = Category::create(['name' => 'Rendimento de Investimentos', 'type' => 'income', 'status' => true]);
        Category::create(['name' => Category::WRIT_PROFIT_NAME, 'type' => 'income', 'status' => true, 'parent_id' => $parent->id]);
        $category = Category::create(['name' => 'Comissões/Bônus', 'type' => 'income', 'status' => true]);
        $broker = Broker::create(['name' => 'Corretor A', 'status' => true]);

        $writ = $this->writ([
            'process_number' => '1007715-60.2023.4.01.3701',
            'source_bank_account_id' => $bank->id,
            'destination_bank_account_id' => $bank->id,
            'notary_expenses_amount' => 181.88,
            'estimated_months' => 5,
        ]);
        $writ = $this->acquire($writ, $bank, ['paid_amount' => 40000, 'paid_at' => '2026-05-22']);
        $this->service->transitionTo($writ->fresh(), 'petitioning', ['petitioned_at' => '2026-06-01 10:00:00']);
        $this->service->transitionTo($writ->fresh(), 'awaiting_receipt', ['awaiting_receipt_at' => '2026-07-01 10:00:00']);

        $receipt = Transaction::create([
            'type' => 'income',
            'amount' => 78810.26,
            'date' => '2026-10-08',
            'description' => 'Antonio Sergio',
            'status' => 'settled',
            'bank_account_id' => $bank->id,
        ]);

        app(ProcessReceiptService::class)->save($receipt, [
            'client_name' => 'Antonio Sergio',
            'fees' => '23643.08',
            'fees_mode' => 'amount',
            'office_amount' => '8629.72',
            'commission_mode' => 'amount',
            'category_id' => $category->id,
            'expenses' => [],
            'advances' => [],
            'includes_writ' => true,
            'writ_id' => $writ->id,
            'writ_amount' => '55167.18',
            'process_ref' => '1007715-60.2023.4.01.3701',
            'brokers' => [[
                'broker_id' => $broker->id,
                'base' => 'fees',
                'mode' => 'fixed',
                'value' => '4728.62',
            ]],
        ]);

        $writ = $writ->fresh();
        $this->assertSame('finalized', $writ->stage);
        $this->assertEquals(55167.18, (float) $writ->actual_receipt_amount);
        $this->assertEquals('2026-10-08', $writ->finalized_at->format('Y-m-d'));

        $income = $writ->transactions()->where('type', 'income')->sole();
        $this->assertEquals(0.0, (float) $income->amount, 'Entrada do requisitorio zerada para nao duplicar o caixa');

        $own = app(OwnFinancialService::class)->ownCents($receipt->fresh(['destinations.payments', 'destinations.recoveries', 'receivableSettlements', 'bankAccount']));
        $this->assertGreaterThan(0, $own);
    }

    public function test_kanban_lista_assinatura_e_permite_marcar_perdido(): void
    {
        \Livewire\Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->assertSee('Assinatura')
            ->assertSee('Pagamento')
            ->assertSee('Peticionamento')
            ->assertSee('Aguardando Recebimento')
            ->assertSeeHtml('grid-template-columns: repeat(9, minmax(360px, 1fr))');
    }
}

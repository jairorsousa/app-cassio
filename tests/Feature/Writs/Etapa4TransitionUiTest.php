<?php

namespace Tests\Feature\Writs;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa4TransitionUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Livewire::withoutLazyLoading();
    }

    public function test_painel_de_perdido_exibe_motivo_obrigatorio_e_nao_nota_opcional(): void
    {
        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'negotiation',
            'assignor_name' => 'Cedente UI',
            'face_value' => 10000,
            'estimated_receipt_amount' => 10000,
        ]);

        Volt::test('writs.show', ['writ' => $writ])
            ->set('transitionTo', 'lost')
            ->assertSee('Motivo da perda (obrigatório)')
            ->assertDontSee('Nota da transição (opcional)')
            ->set('transition_lost_reason', '')
            ->call('transition')
            ->assertHasErrors('transition_lost_reason');

        $this->assertSame('negotiation', $writ->fresh()->stage);
    }

    public function test_painel_de_assinatura_exibe_e_grava_data(): void
    {
        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'pending',
            'assignor_name' => 'Cedente Assinatura',
            'face_value' => 10000,
            'cession_at' => '2026-03-01 10:00:00',
            'estimated_receipt_amount' => 10000,
        ]);

        Volt::test('writs.show', ['writ' => $writ])
            ->set('transitionTo', 'signature')
            ->assertSee('Data e hora da assinatura')
            ->assertSet('transition_signature_at', fn ($value) => filled($value))
            ->set('transition_signature_at', '2026-03-20T14:30')
            ->call('transition')
            ->assertHasNoErrors();

        $writ = $writ->fresh();
        $this->assertSame('signature', $writ->stage);
        $this->assertSame('2026-03-20 14:30:00', $writ->signature_at->format('Y-m-d H:i:s'));
        $this->assertTrue(
            $writ->history()->where('to_stage', 'signature')->exists()
        );
    }

    public function test_fluxo_de_caixa_recalcula_na_primeira_mudanca_de_mes(): void
    {
        $bank = BankAccount::create(['name' => 'CEF', 'initial_balance' => 0, 'status' => true]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $parent = Category::create(['name' => 'Rendimento de Investimentos', 'type' => 'income', 'status' => true]);
        Category::create(['name' => Category::WRIT_PROFIT_NAME, 'type' => 'income', 'status' => true, 'parent_id' => $parent->id]);
        $category = Category::create(['name' => 'Comissões/Bônus', 'type' => 'income', 'status' => true]);
        $broker = Broker::create(['name' => 'Corretor A', 'status' => true]);

        $writ = Writ::create([
            'type' => 'precatorio',
            'stage' => 'awaiting_receipt',
            'assignor_name' => 'Antonio Sergio',
            'process_number' => '1007715-60.2023.4.01.3701',
            'face_value' => 60000,
            'paid_amount' => 40000,
            'notary_expenses_amount' => 181.88,
            'paid_at' => '2026-05-22',
            'awaiting_receipt_at' => '2026-07-01 10:00:00',
            'source_bank_account_id' => $bank->id,
            'destination_bank_account_id' => $bank->id,
            'estimated_receipt_amount' => 55167.18,
        ]);

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

        $this->assertSame('finalized', $writ->fresh()->stage);
        $this->assertEqualsWithDelta(14985.30, $writ->fresh()->actualProfit(), 0.01);

        // Mês atual (nov/2026) sem o recebimento — depois troca para out/2026 na mesma instância.
        $component = Volt::test('banking.reports.cashflow')
            ->assertSet('month', '2026-11');

        $nov = $component->viewData('byCategory');
        $this->assertTrue(
            ! $nov->has(Category::WRIT_PROFIT_NAME)
            || (float) ($nov->get(Category::WRIT_PROFIT_NAME)['income'] ?? 0) === 0.0
        );

        $component->set('month', '2026-10');
        $this->assertSame('2026-10', $component->get('month'));

        $oct = $component->viewData('byCategory');
        $this->assertEqualsWithDelta(14985.30, (float) $oct->get(Category::WRIT_PROFIT_NAME)['income'], 0.01);
        $this->assertEqualsWithDelta(40181.88, (float) $oct->get('Retorno de capital (RPV/Precatórios)')['income'], 0.01);

        // Segunda leitura do mesmo mês não duplica.
        $component->set('month', '2026-11')->set('month', '2026-10');
        $octAgain = $component->viewData('byCategory');
        $this->assertEqualsWithDelta(14985.30, (float) $octAgain->get(Category::WRIT_PROFIT_NAME)['income'], 0.01);
        $this->assertEqualsWithDelta(40181.88, (float) $octAgain->get('Retorno de capital (RPV/Precatórios)')['income'], 0.01);
    }

    public function test_kanban_ao_escolher_assinatura_pede_data(): void
    {
        Volt::test('writs.kanban')
            ->call('create', 'signature')
            ->assertSet('stage', 'signature')
            ->assertSet('signature_at', fn ($value) => filled($value))
            ->assertSee('Data e hora da assinatura');
    }
}

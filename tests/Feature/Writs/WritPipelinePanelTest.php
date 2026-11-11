<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritPipelinePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
    }

    public function test_painel_mostra_recebido_por_mes_medias_e_previsao_vencida(): void
    {
        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'paid_amount' => 10000,
            'actual_receipt_amount' => 15000,
            'paid_at' => '2025-12-01',
            'finalized_at' => '2026-05-10',
            'estimated_months' => 9,
        ]);
        Writ::create([
            'type' => 'precatorio',
            'stage' => 'awaiting_receipt',
            'paid_amount' => 20000,
            'estimated_receipt_amount' => 35000,
            'paid_at' => '2024-01-10',
            'estimated_months' => 24,
        ]);
        Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'paid_amount' => 8000,
            'estimated_receipt_amount' => 12000,
            'paid_at' => '2026-08-01',
            'estimated_months' => 9,
        ]);
        Writ::create([
            'type' => 'precatorio',
            'stage' => 'monitoring',
            'face_value' => 50000,
        ]);

        Volt::test('writs.kanban')
            ->assertSee('Dinheiro investido aguardando pagamento')
            ->assertSee('R$ 28.000,00')
            ->assertSee('Quanto deve voltar')
            ->assertSee('R$ 47.000,00')
            ->assertSee('Já recebido')
            ->assertSee('R$ 15.000,00')
            ->assertSee('Total investido até hoje')
            ->assertSee('R$ 38.000,00')
            ->assertSee('Situação agora')
            ->assertSee('Aguardando o tribunal')
            ->assertSee('Com previsão vencida')
            ->assertSee('Já recebidos')
            ->assertSee('Em análise (ainda não comprados)')
            ->assertSee('Recebido por mês')
            ->assertSee('Últimos 12 meses')
            ->assertSee('mai/26')
            ->assertSee('Resumo por ano')
            ->assertSee('Médias')
            ->assertSee('Média mensal em 2026')
            ->assertSee('Tempo médio entre a compra e o recebimento')
            ->assertSee('Lucro médio por requisitório recebido')
            ->assertSee('Próximos recebimentos previstos')
            ->assertSee('1 com previsão vencida')
            ->assertSee('devem voltar R$ 35.000,00')
            ->assertSee('mai/27')
            ->assertDontSee('Em aberto (já pagos)')
            ->assertDontSee('Custo total (pago + despesas)');
    }

    public function test_seletor_de_ano_troca_o_grafico_mensal(): void
    {
        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'paid_amount' => 10000,
            'actual_receipt_amount' => 18000,
            'paid_at' => '2024-01-01',
            'finalized_at' => '2024-06-15',
        ]);
        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'paid_amount' => 5000,
            'actual_receipt_amount' => 8000,
            'paid_at' => '2026-01-01',
            'finalized_at' => '2026-03-10',
        ]);

        Volt::test('writs.kanban')
            ->assertSee('Últimos 12 meses')
            ->assertSee('2024')
            ->assertSee('2026')
            ->assertSee('mar/26')
            ->assertDontSee('jun/24')
            ->call('setMetricsPeriod', '2024')
            ->assertSet('metricsPeriod', '2024')
            ->assertSee('jun/24')
            ->assertSee('R$ 18.000,00');
    }

    public function test_dica_de_ajuda_explica_os_numeros(): void
    {
        Volt::test('writs.kanban')
            ->assertSee('O que significa este número')
            ->assertSee('já pagou (compra + cartório e outras despesas)')
            ->assertSee('operações muito curtas ficam de fora');
    }
}

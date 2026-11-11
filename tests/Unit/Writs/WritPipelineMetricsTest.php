<?php

namespace Tests\Unit\Writs;

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritPipelineMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WritPipelineMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_totais_do_painel_separam_aberto_recebido_e_pipeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'precatorio',
            'stage' => 'monitoring',
            'face_value' => 100000,
            'proposed_amount' => 40000,
        ]);
        Writ::create([
            'type' => 'rpv',
            'stage' => 'awaiting_receipt',
            'face_value' => 20000,
            'negotiated_amount' => 18000,
            'paid_amount' => 10000,
            'notary_expenses_amount' => 200,
            'estimated_receipt_amount' => 16000,
            'estimated_months' => 9,
            'paid_at' => '2026-01-15',
        ]);
        Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'face_value' => 15000,
            'negotiated_amount' => 14000,
            'paid_amount' => 8000,
            'other_expenses_amount' => 100,
            'actual_receipt_amount' => 12000,
            'estimated_months' => 9,
            'paid_at' => '2026-02-01',
            'finalized_at' => '2026-08-20',
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all());

        $this->assertSame(10200.0, $metrics['open']['cost']);
        $this->assertSame(16000.0, $metrics['open']['expected_receipt']);
        $this->assertSame(5800.0, $metrics['open']['expected_profit']);
        $this->assertSame(56.86, $metrics['open']['expected_profit_pct']);
        $this->assertSame(1, $metrics['open']['count']);
        $this->assertSame(0, $metrics['open']['without_cost']);

        $this->assertSame(12000.0, $metrics['received']['amount']);
        $this->assertSame(8100.0, $metrics['received']['cost']);
        $this->assertSame(3900.0, $metrics['received']['profit']);
        $this->assertSame(48.15, $metrics['received']['profit_pct']);

        $this->assertSame(18300.0, $metrics['invested']['total']);
        $this->assertSame(2, $metrics['invested']['acquired_count']);
        $this->assertSame(35000.0, $metrics['invested']['face']);
        $this->assertSame(32000.0, $metrics['invested']['negotiated']);
        $this->assertSame(1, $metrics['situation']['pipeline_count']);
        $this->assertSame(0, $metrics['situation']['lost_count']);
    }

    public function test_recebido_por_mes_e_por_ano_usa_a_data_de_recebimento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 10000, 'actual_receipt_amount' => 15000,
            'paid_at' => '2025-01-10', 'finalized_at' => '2025-12-15',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 20000, 'actual_receipt_amount' => 28000,
            'paid_at' => '2026-03-01', 'finalized_at' => '2026-05-10',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 5000, 'actual_receipt_amount' => 7000,
            'paid_at' => '2026-04-01', 'finalized_at' => '2026-05-20',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 8000, 'actual_receipt_amount' => 9000,
            'paid_at' => '2024-01-01', 'finalized_at' => '2024-06-01',
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all(), '12m');

        $this->assertSame('12m', $metrics['chart']['period']);
        $this->assertSame('dez/25', $metrics['chart']['labels'][0]);
        $this->assertSame('nov/26', $metrics['chart']['labels'][11]);
        $this->assertSame(15000.0, $metrics['chart']['months'][0]['received']);
        $this->assertSame(5000.0, $metrics['chart']['months'][0]['profit']);
        $this->assertSame(1, $metrics['chart']['months'][0]['count']);
        $this->assertSame(35000.0, $metrics['chart']['months'][5]['received']);
        $this->assertSame(10000.0, $metrics['chart']['months'][5]['profit']);
        $this->assertSame(2, $metrics['chart']['months'][5]['count']);
        $this->assertSame(0.0, $metrics['chart']['months'][11]['received']);

        $byYear = collect($metrics['years'])->keyBy('year');
        $this->assertSame(35000.0, $byYear[2026]['received']);
        $this->assertSame(25000.0, $byYear[2026]['cost']);
        $this->assertSame(10000.0, $byYear[2026]['profit']);
        $this->assertSame(40.0, $byYear[2026]['profit_pct']);
        $this->assertSame(2, $byYear[2026]['count']);
        $this->assertSame(15000.0, $byYear[2025]['received']);
        $this->assertSame(9000.0, $byYear[2024]['received']);

        $yearChart = (new WritPipelineMetrics)->forCollection(Writ::all(), '2026');
        $this->assertSame('2026', $yearChart['chart']['period']);
        $this->assertSame('jan/26', $yearChart['chart']['labels'][0]);
        $this->assertSame(35000.0, $yearChart['chart']['months'][4]['received']);
        $this->assertSame(0.0, $yearChart['chart']['months'][0]['received']);
    }

    public function test_medias_dividem_pelos_meses_do_periodo_incluindo_os_zerados(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 10000, 'actual_receipt_amount' => 22000,
            'paid_at' => '2026-01-01', 'finalized_at' => '2026-05-01',
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all());
        $avg = $metrics['averages'];

        $this->assertSame(2026, $avg['year']);
        $this->assertSame(11, $avg['months_elapsed_year']);
        $this->assertSame(2000.0, $avg['monthly_received_year']);
        $this->assertSame(1090.91, $avg['monthly_profit_year']);
        $this->assertSame(1833.33, $avg['monthly_received_12m']);
        $this->assertSame(1000.0, $avg['monthly_profit_12m']);
        $this->assertSame(120, $avg['avg_days_to_receipt']);
        $this->assertSame(3.9, $avg['avg_months_to_receipt']);
        $this->assertSame(12000.0, $avg['avg_profit_per_writ']);
    }

    public function test_rentabilidade_mensal_equivalente_ignora_operacoes_curtas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 10000, 'actual_receipt_amount' => 13000,
            'paid_at' => '2025-01-01', 'finalized_at' => '2026-01-01',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 20000, 'actual_receipt_amount' => 24000,
            'paid_at' => '2025-06-01', 'finalized_at' => '2026-06-01',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 15000, 'actual_receipt_amount' => 18000,
            'paid_at' => '2025-03-01', 'finalized_at' => '2026-03-01',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 8000, 'actual_receipt_amount' => 11000,
            'paid_at' => '2026-09-01', 'finalized_at' => '2026-09-02',
        ]);

        $rate = (new WritPipelineMetrics)->forCollection(Writ::all())['averages']['monthly_equivalent'];

        $this->assertNotNull($rate);
        $this->assertSame(3, $rate['count']);
        $this->assertSame(1, $rate['excluded_short']);
        $this->assertEqualsWithDelta(1.8, $rate['percent'], 0.3);
        $this->assertTrue($rate['percent'] > 0 && $rate['percent'] < 10);
    }

    public function test_previsao_vencida_aparece_separada_e_nao_entra_nos_meses_futuros(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'rpv', 'stage' => 'awaiting_receipt',
            'paid_amount' => 10000, 'estimated_receipt_amount' => 16000,
            'estimated_months' => 9, 'paid_at' => '2025-01-01',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'awaiting_receipt',
            'paid_amount' => 5000, 'estimated_receipt_amount' => 8000,
            'estimated_months' => 9, 'paid_at' => '2026-06-01',
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all());

        $this->assertSame(1, $metrics['open']['overdue_count']);
        $this->assertSame(16000.0, $metrics['upcoming']['overdue']['expected_receipt']);
        $this->assertSame(10000.0, $metrics['upcoming']['overdue']['cost']);
        $this->assertCount(1, $metrics['upcoming']['months']);
        $this->assertSame('mar/27', $metrics['upcoming']['months'][0]['label']);
        $this->assertSame(8000.0, $metrics['upcoming']['months'][0]['expected_receipt']);
    }

    public function test_recebimento_sem_data_nao_entra_no_grafico_e_e_relatado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));

        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 4000, 'actual_receipt_amount' => 7000,
            'paid_at' => '2026-01-01', 'finalized_at' => '2026-08-01',
        ]);
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 1000, 'actual_receipt_amount' => 2000,
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all());

        $this->assertSame(9000.0, $metrics['received']['amount']);
        $this->assertSame(1, $metrics['received']['without_date']);
        $this->assertSame(7000.0, collect($metrics['chart']['months'])->sum('received'));
        $this->assertTrue(collect($metrics['anomalies'])->contains(fn ($row) => $row['issue'] === 'recebido_sem_data'));
    }

    public function test_periodo_invalido_volta_para_os_ultimos_12_meses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11', 'America/Fortaleza'));
        Writ::create([
            'type' => 'rpv', 'stage' => 'finalized',
            'paid_amount' => 1000, 'actual_receipt_amount' => 1000,
            'finalized_at' => '2026-01-10',
        ]);

        $metrics = (new WritPipelineMetrics)->forCollection(Writ::all(), '1999');
        $this->assertSame('12m', $metrics['period']);
    }
}

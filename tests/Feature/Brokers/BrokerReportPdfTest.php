<?php

namespace Tests\Feature\Brokers;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrokerReportPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-11 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_page_displays_pdf_action_with_current_filters(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('brokers.reports'));

        $response->assertOk()
            ->assertSee('Gerar PDF')
            ->assertSee(route('brokers.reports.pdf', [
                'month' => '8',
                'year' => '2026',
            ]));
    }

    public function test_authenticated_user_can_download_filtered_broker_report_pdf(): void
    {
        $broker = Broker::create(['name' => 'Ana Corretora']);
        $caseType = CaseType::create(['name' => 'Previdenciário']);

        BrokerCommission::create([
            'broker_id' => $broker->id,
            'case_type_id' => $caseType->id,
            'base_amount' => 1000,
            'percentage_applied' => 10,
            'commission_amount' => 100,
            'status' => 'paid',
            'reference_date' => '2026-08-05',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('brokers.reports.pdf', [
                'month' => '8',
                'year' => '2026',
                'broker_id' => $broker->id,
            ]));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('relatorio-corretores-2026-08-01-a-2026-08-31.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_download_requires_authentication(): void
    {
        $this->get(route('brokers.reports.pdf', [
            'month' => '8',
            'year' => '2026',
        ]))->assertRedirect(route('login'));
    }

    public function test_pdf_accepts_open_ended_custom_periods(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('brokers.reports.pdf', [
                'month' => '8',
                'year' => '2026',
                'start_date' => '2026-08-05',
            ]))
            ->assertOk()
            ->assertDownload('relatorio-corretores-2026-08-05.pdf');

        $this->get(route('brokers.reports.pdf', [
            'month' => '8',
            'year' => '2026',
            'end_date' => '2026-08-20',
        ]))
            ->assertOk()
            ->assertDownload('relatorio-corretores-2026-08-20.pdf');
    }
}

<?php

namespace Tests\Feature\Writs;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WritPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_page_only_displays_the_generate_pdf_action(): void
    {
        $user = User::factory()->create();
        $writ = $this->createWrit();

        $this->actingAs($user)
            ->get(route('writs.show', $writ))
            ->assertOk()
            ->assertSee('Gerar PDF')
            ->assertSee(route('writs.pdf', $writ))
            ->assertDontSee('Ver cálculo')
            ->assertDontSee('Exibir requisitório');
    }

    public function test_details_page_displays_monthly_actual_profit_in_amount_above_percentage(): void
    {
        $user = User::factory()->create();
        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'paid_amount' => 800,
            'actual_receipt_amount' => 1100,
            'paid_at' => '2026-01-15',
            'finalized_at' => '2026-04-15',
        ]);

        $this->actingAs($user)
            ->get(route('writs.show', $writ))
            ->assertOk()
            ->assertSeeInOrder([
                'Lucro real / Mês (R$)',
                'R$ 100,00',
                'Lucro real / Mês (%)',
                '12,50%',
            ]);
    }

    public function test_details_page_displays_the_complete_writ_dates_block(): void
    {
        $user = User::factory()->create();
        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'monitoring_at' => '2025-01-02 09:00:00',
            'negotiation_at' => '2025-01-03 10:30:00',
            'cession_at' => '2025-01-04 11:00:00',
            'paid_at' => '2025-01-05',
            'petitioned_at' => '2025-01-06 14:00:00',
            'awaiting_receipt_at' => '2025-02-10 09:30:00',
            'finalized_at' => '2025-02-11',
        ]);

        $this->actingAs($user)
            ->get(route('writs.show', $writ))
            ->assertOk()
            ->assertSee('Datas do requisitório')
            ->assertSeeInOrder([
                'Monitoramento',
                '02/01/2025 09:00',
                'Negociação',
                '03/01/2025 10:30',
                'Cessão',
                '04/01/2025 11:00',
                'Pagamento',
                '05/01/2025',
                'Peticionamento',
                '06/01/2025 14:00',
                'Previsão de recebimento',
                '10/02/2025 09:30',
                'Recebimento',
                '11/02/2025',
            ]);
    }

    public function test_authenticated_user_can_download_the_writ_pdf(): void
    {
        $user = User::factory()->create();
        $writ = $this->createWrit();
        $contact = Contact::create([
            'name' => 'Rosineide Souza da Silva',
            'document' => '123.456.789-00',
            'phone' => '(98) 99999-0000',
            'status' => true,
        ]);

        WritAssignor::create([
            'writ_id' => $writ->id,
            'contact_id' => $contact->id,
            'role' => 'parte',
        ]);

        $response = $this->actingAs($user)->get(route('writs.pdf', $writ));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('requisitorio-0901037-10-2019-8-10-0131.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_pdf_download_requires_authentication(): void
    {
        $writ = $this->createWrit();

        $this->get(route('writs.pdf', $writ))
            ->assertRedirect(route('login'));
    }

    private function createWrit(): Writ
    {
        return Writ::create([
            'type' => 'rpv',
            'stage' => 'finalized',
            'assignor_name' => 'Rosineide Souza da Silva',
            'process_number' => '0901037-10.2019.8.10.0131',
            'court' => 'Tribunal de Justiça do Maranhão',
            'debtor_entity' => 'Estado do Maranhão',
            'credit_nature' => 'Alimentar',
            'face_value' => 81832.82,
            'negotiated_amount' => 57282.97,
            'proposed_amount' => 33174.73,
            'paid_amount' => 33174.73,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'estimated_receipt_amount' => 68449.18,
            'actual_receipt_amount' => 68449.18,
            'paid_at' => '2023-09-26',
            'finalized_at' => '2026-07-01',
            'notes' => 'Documento de teste do requisitório.',
        ]);
    }
}

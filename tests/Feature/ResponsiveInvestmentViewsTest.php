<?php

namespace Tests\Feature;

use App\Domains\Investments\Models\BrokerStatementImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ResponsiveInvestmentViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subnav_is_single_row_scroll_on_mobile_and_keeps_order(): void
    {
        $html = view('components.investments.subnav')->render();
        $this->assertStringContainsString('flex-nowrap', $html);
        $this->assertStringContainsString('lg:flex-wrap', $html);
        $labels = ['Visão geral', 'Carteira', 'Movimentações', 'Proventos', 'Posição B3', 'Extratos', 'Bens', 'Ativos', 'Rentabilidade'];
        $pos = array_map(fn ($l) => strpos($html, $l), $labels);
        $sorted = $pos; sort($sorted);
        $this->assertSame($sorted, $pos);
    }

    public function test_b3_page_has_mobile_cards_and_desktop_table(): void
    {
        $this->actingAs(User::factory()->create());
        Volt::test('investments.b3.index')->assertSeeHtml('data-mobile="b3-income"')->assertSeeHtml('hidden overflow-x-auto md:block');
    }

    public function test_batch_rows_render_as_mobile_cards_too(): void
    {
        $u = User::factory()->create();
        $b = BrokerStatementImport::create(['broker_name' => 'BTG', 'file_name' => 'x.pdf', 'file_kind' => 'pdf', 'file_hash' => 'h', 'status' => 'confirmed', 'created_by' => $u->id, 'expires_at' => now()->addDay()]);
        $b->rows()->create(['row_number' => 1, 'date' => '2026-03-01', 'kind' => 'other', 'source_type_label' => 'LINHA UNICA', 'net_amount' => -5, 'status' => 'held_review', 'raw' => []]);
        $this->actingAs($u);
        $html = Volt::test('investments.statements.index')->call('openBatch', $b->id)->assertSeeHtml('data-mobile="batch-rows"')->html();
        $this->assertSame(2, substr_count($html, 'LINHA UNICA'));
    }
}

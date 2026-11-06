<?php

namespace Tests\Feature;

use App\Domains\Investments\Models\BrokerStatementImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StatementBatchViewTest extends TestCase
{
    use RefreshDatabase;

    private function batch(User $u): BrokerStatementImport
    {
        $b = BrokerStatementImport::create(['broker_name' => 'BTG', 'file_name' => 'x.pdf', 'file_kind' => 'pdf', 'file_hash' => 'h', 'status' => 'confirmed', 'created_by' => $u->id, 'expires_at' => now()->addDay()]);
        foreach ([['held_review', 'JCP LOJAS'], ['imported', 'TAXA BTC AAAA3'], ['duplicate_not_imported', 'DIVIDENDOS BBBB3'], ['pending_manual', 'TED']] as $i => [$st, $d]) {
            $b->rows()->create(['row_number' => $i + 1, 'date' => '2026-03-0'.($i + 1), 'kind' => 'other', 'source_type_label' => $d, 'net_amount' => 10 + $i, 'status' => $st, 'raw' => []]);
        }

        return $b;
    }

    public function test_opens_batch_with_review_default_and_filters(): void
    {
        $u = User::factory()->create();
        $b = $this->batch($u);
        $this->actingAs($u);
        Volt::test('investments.statements.index')->call('openBatch', $b->id)
            ->assertSee('JCP LOJAS')->assertDontSee('TAXA BTC AAAA3')->assertSee('Todos (4)')
            ->set('viewGroup', 'imported')->assertSee('TAXA BTC AAAA3')->assertDontSee('JCP LOJAS')
            ->set('viewGroup', 'all')->set('viewSearch', 'BBBB3')->assertSee('Duplicado, não importado')->assertDontSee('TED</td>', false);
    }

    public function test_status_count_query_is_group_by_safe(): void
    {
        $u = User::factory()->create();
        $b = $this->batch($u);
        $this->actingAs($u);
        \DB::enableQueryLog();
        Volt::test('investments.statements.index')->call('openBatch', $b->id)->assertOk();
        $grouped = collect(\DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'group by'));
        $this->assertNotEmpty($grouped);
        foreach ($grouped as $q) {
            $this->assertStringNotContainsString('order by', $q, 'GROUP BY com ORDER BY quebra no MySQL only_full_group_by');
        }
    }

    public function test_other_user_cannot_open_batch(): void
    {
        $b = $this->batch(User::factory()->create());
        $this->actingAs(User::factory()->create());
        $c = Volt::test('investments.statements.index')->assertDontSee('x.pdf');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $c->call('openBatch', $b->id);
    }

    public function test_investments_bens_redirects(): void
    {
        $this->get('/investments/bens')->assertRedirect('/bens');
    }
}

<?php

namespace Tests\Feature\Investments;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PositionPriceFourDecimalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_accepts_br_four_decimals_and_totals_keep_two(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($u);
        $class = \App\Domains\Investments\Models\AssetClass::query()->create(['name' => 'Ações', 'code' => 'acoes', 'slug' => 'acoes']);
        $asset = \App\Domains\Investments\Models\Asset::query()->create(['ticker' => 'TEST4', 'name' => 'Teste', 'asset_class_id' => $class->id]);
        \App\Domains\Investments\Models\AssetPosition::query()->create(['asset_id' => $asset->id, 'quantity' => 100, 'average_price' => 10.1234, 'total_invested' => 1012.34]);

        $page = Volt::test('investments.positions')->assertSee('R$ 10,1234')->assertSee('R$ 1.012,34')
            ->call('startQuote', $asset->id)->assertSet('quotePrice', '10.1234')->assertSeeHtml('x-decimal.pad="4"');
        $page->set('quotePrice', '12,3456')->call('saveQuote')->assertHasNoErrors();
        $this->assertSame('12.3456', number_format((float) $asset->fresh()->position->current_price, 4, '.', ''));
        Volt::test('investments.positions')->assertSee('R$ 12,3456')->assertSee('R$ 1.234,56');
    }
}

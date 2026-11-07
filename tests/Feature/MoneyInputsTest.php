<?php

namespace Tests\Feature;

use App\Domains\Assets\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MoneyInputsTest extends TestCase
{
    use RefreshDatabase;

    public function test_novo_bem_uses_brazilian_money_mask(): void
    {
        $this->actingAs(User::factory()->create());
        $html = Volt::test('bens.index')->call('create')->html();
        $this->assertSame(2, substr_count($html, 'x-money'));
        $this->assertStringNotContainsString('placeholder="0.00"', $html);
    }

    public function test_novo_bem_saves_pasted_brazilian_values(): void
    {
        $this->actingAs(User::factory()->create());
        Volt::test('bens.index')->call('create')
            ->set('kind', array_key_first(Property::KINDS))
            ->set('description', 'Apartamento')
            ->set('acquisition_value', 'R$ 1.234,56')
            ->set('current_value', '250000,5')
            ->call('save')->assertHasNoErrors();
        $p = Property::where('description', 'Apartamento')->firstOrFail();
        $this->assertSame(1234.56, (float) $p->acquisition_value);
        $this->assertSame(250000.5, (float) $p->current_value);
        $this->assertNull($p->sale_value);
    }

    public function test_money_views_have_no_raw_number_inputs_for_values(): void
    {
        $views = [
            'bens/index', 'brokers/process-expenses', 'banking/cards/invoices', 'brokers/commissions/form',
            'brokers/index', 'brokers/show', 'banking/transactions/index', 'banking/transactions/process-receipt-form',
        ];
        foreach ($views as $view) {
            $source = file_get_contents(resource_path("views/livewire/{$view}.blade.php"));
            $this->assertStringContainsString('x-money', $source, $view);
            $this->assertDoesNotMatchRegularExpression('/type="number"[^>]*wire:model[^>]*\.amount"/', $source, $view);
        }
    }
}

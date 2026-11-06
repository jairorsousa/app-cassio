<?php

namespace Tests\Feature;

use App\Domains\Assets\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BensResponsiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_state_without_table(): void
    {
        $this->actingAs(User::factory()->create());
        Volt::test('bens.index')->assertSeeHtml('data-empty="bens"')->assertDontSeeHtml('<table');
    }

    public function test_cards_and_table_for_records(): void
    {
        $this->actingAs(User::factory()->create());
        Property::create(['kind' => array_key_first(Property::KINDS), 'description' => 'Casa Praia', 'acquisition_value' => 100, 'current_value' => 90, 'status' => 'sold', 'sold_at' => '2026-10-01', 'sale_value' => 120]);
        $html = Volt::test('bens.index')->assertSeeHtml('data-mobile="bens"')->assertSeeHtml('hidden overflow-x-auto md:block')->assertSee('Vendido em 01/10/2026')->html();
        $this->assertSame(2, substr_count($html, 'Casa Praia'));
        $this->assertSame(2, substr_count($html, 'wire:click="edit('));
    }
}

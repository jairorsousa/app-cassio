<?php

namespace Tests\Feature\Writs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class KanbanColumnColorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_are_neutral_with_thin_flow_bar(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($u);
        \Livewire\Livewire::withoutLazyLoading();
        $html = Volt::test('writs.kanban')->html();
        $this->assertStringContainsString('data-flow-color="#7dd3fc"', $html);
        $this->assertStringContainsString('data-flow-color="#14b8a6"', $html);
        $this->assertStringContainsString('style="height:4px;background:#6366f1"', $html);
        $this->assertStringNotContainsString('bg-amber-50/70', $html);
        $this->assertStringNotContainsString('bg-emerald-50/70', $html);
    }
}

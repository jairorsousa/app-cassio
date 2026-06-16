<?php

namespace Tests\Feature\Writs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritKanbanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_displays_awaiting_receipt_stage(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();

        Volt::test('writs.kanban')
            ->assertSee('Aguardando Recebimento')
            ->assertSeeHtml('grid-cols-6');
    }
}

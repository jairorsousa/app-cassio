<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritDeadlineStatusTest extends TestCase
{
    use RefreshDatabase;

    private function w(array $a): Writ
    {
        return Writ::forceCreate(array_merge(['type' => 'rpv', 'stage' => 'awaiting_receipt', 'process_number' => uniqid(), 'face_value' => 1000, 'assignor_name' => 'Fulano'], $a));
    }

    public function test_status_rules(): void
    {
        Carbon::setTestNow('2026-11-07');
        $this->assertSame('on_time', $this->w(['paid_at' => '2026-11-01', 'estimated_months' => 9])->deadlineStatus()['key']);
        $s = $this->w(['paid_at' => '2026-03-01', 'estimated_months' => 9])->deadlineStatus();
        $this->assertSame('due_soon', $s['key']);
        $this->assertSame('Vence em 24 dias', $s['label']);
        $o = $this->w(['paid_at' => '2025-01-07', 'estimated_months' => 9])->deadlineStatus();
        $this->assertSame('Atrasado há 13 meses', $o['label']);
        $this->assertSame('received', $this->w(['stage' => 'finalized', 'paid_at' => '2025-01-01', 'estimated_months' => 9])->deadlineStatus()['key']);
        $this->assertNull($this->w(['stage' => 'monitoring'])->deadlineStatus());
        $this->assertNull($this->w(['paid_at' => null, 'estimated_months' => 9])->deadlineStatus());
    }

    public function test_kanban_badge_filter_and_report(): void
    {
        Carbon::setTestNow('2026-11-07');
        $this->actingAs(User::factory()->create());
        $this->w(['assignor_name' => 'Maria Atrasada', 'paid_at' => '2025-01-07', 'estimated_months' => 9]);
        $this->w(['assignor_name' => 'João NoPrazo', 'paid_at' => '2026-11-01', 'estimated_months' => 9]);
        \Livewire\Livewire::withoutLazyLoading();
        Volt::test('writs.kanban')->assertSee('Atrasado há 13 meses')->assertSee('22 meses desde a compra')->assertSee('6 dias desde a compra')->assertSee('João NoPrazo')
            ->set('situation', 'overdue')->assertSee('Maria Atrasada')->assertDontSee('João NoPrazo');
        $this->get(route('writs.reports'))->assertOk()->assertSee('Situação dos requisitórios comprados')->assertSee('Maria Atrasada')->assertSee('Atrasado há 13 meses');
    }
}

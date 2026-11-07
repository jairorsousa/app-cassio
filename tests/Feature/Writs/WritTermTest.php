<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritReceiptTimeReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritTermTest extends TestCase
{
    use RefreshDatabase;

private function termWrit(array $a): Writ
{
    return Writ::forceCreate(array_merge(['type' => 'rpv', 'stage' => 'finalized', 'process_number' => uniqid(), 'face_value' => 1000, 'paid_amount' => 500], $a));
}

public function test_defaults_and_report(): void {
    $this->assertSame(24, Writ::defaultTermMonths('precatorio'));
    $this->assertSame(9, Writ::defaultTermMonths('rpv'));
    $this->termWrit(['court' => 'TJMA / Vara', 'paid_at' => '2025-01-01', 'finalized_at' => '2025-07-01', 'estimated_months' => 9]);
    $this->termWrit(['court' => 'TJMA', 'paid_at' => '2025-01-01', 'finalized_at' => '2025-11-01', 'estimated_months' => 9]);
    $this->termWrit(['type' => 'precatorio', 'court' => 'TRF!', 'paid_at' => '2024-01-01', 'finalized_at' => '2026-01-01', 'estimated_months' => 24]);
    $this->termWrit(['stage' => 'paid', 'paid_at' => '2025-01-01', 'estimated_months' => 9]);
    $s = app(WritReceiptTimeReport::class)->summary();
    $rpv = collect($s['by_type'])->firstWhere('label', 'RPV');
    $this->assertSame(2, $rpv['count']);
    $this->assertEquals(9.0, $rpv['avg_estimated']);
    $this->assertEqualsWithDelta(8.0, $rpv['avg_months'], 0.15);
    $labels = collect($s['by_court'])->pluck('label')->all();
    $this->assertContains('TJMA · RPV', $labels);
    $this->assertContains('TRF1 · Precatório', $labels);
}

public function test_page_and_selector(): void {
    $this->actingAs(User::factory()->create());
    $this->termWrit(['court' => 'TJMA', 'paid_at' => '2025-01-01', 'finalized_at' => '2025-07-01', 'estimated_months' => 9, 'actual_receipt_amount' => 900]);
    $this->get(route('writs.reports'))->assertOk()->assertSee('Tempo de recebimento')->assertSee('TJMA · RPV');
    $c = Volt::test('writs.form')->assertSet('estimated_months', 9)->set('type', 'precatorio')->assertSet('estimated_months', 24);
    $c->set('estimated_months', 18)->set('type', 'rpv')->assertSet('estimated_months', 18);
    $this->get(route('writs.create'))->assertOk()->assertSee('data-writ-term', false);
    Volt::test('writs.kanban')->call('create')->assertSet('estimated_months', 9)->set('formType', 'precatorio')->assertSet('estimated_months', 24);
}
}

<?php

namespace Tests\Unit\Writs;

use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritStageHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WritDiscountPercentageTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_discount_percentage_clamps_out_of_range_values(): void
    {
        $this->assertSame(20.0, Writ::calculateDiscountPercentage(10_000, 8_000));
        $this->assertSame(-999.999, Writ::calculateDiscountPercentage(100, 8_000));
        $this->assertSame(0.0, Writ::calculateDiscountPercentage(0, 8_000));
    }

    public function test_editing_petition_date_saves_when_discount_would_overflow(): void
    {
        $writ = Writ::create([
            'type' => 'rpv',
            'stage' => 'petitioning',
            'process_number' => '0001234-56.2026.8.13.0001',
            'face_value' => 100,
            'negotiated_amount' => 100,
            'proposed_amount' => 8_000,
            'paid_amount' => 8_000,
            'notary_expenses_amount' => 0,
            'other_expenses_amount' => 0,
            'discount_percentage' => 20,
            'estimated_receipt_amount' => 12_000,
            'paid_at' => now()->toDateString(),
            'petitioned_at' => '2026-06-22 15:00:00',
        ]);

        $this->actingAs(User::factory()->create());

        Volt::test('writs.form', ['writ' => $writ])
            ->set('petitioned_at', '2026-06-23T10:30')
            ->call('save')
            ->assertHasNoErrors();

        $writ->refresh();

        $this->assertSame('2026-06-23 10:30:00', $writ->petitioned_at->format('Y-m-d H:i:s'));
        $this->assertSame('-999.999', $writ->discount_percentage);
        $this->assertSame(1, WritStageHistory::query()->where('writ_id', $writ->id)->count());
    }
}
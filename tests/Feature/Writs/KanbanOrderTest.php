<?php

namespace Tests\Feature\Writs;

use App\Domains\Writs\Models\Writ;
use Carbon\Carbon;
use Tests\TestCase;

class KanbanOrderTest extends TestCase
{
    private function w(int $id, string $stage, ?string $paid, ?int $months = 12, ?string $fin = null): Writ
    {
        $w = new Writ;
        $w->forceFill(['stage' => $stage, 'paid_at' => $paid, 'estimated_months' => $months, 'finalized_at' => $fin]);
        $w->id = $id;

        return $w;
    }

    public function test_open_column_order_red_then_yellow_then_green_then_no_date(): void
    {
        $asOf = Carbon::parse('2026-11-08');
        Carbon::setTestNow($asOf);
        $cards = collect([
            $this->w(1, 'awaiting_receipt', '2025-12-01'),
            $this->w(2, 'awaiting_receipt', '2025-01-01'),
            $this->w(3, 'awaiting_receipt', null),
            $this->w(4, 'awaiting_receipt', '2026-06-01'),
            $this->w(5, 'awaiting_receipt', '2025-10-01'),
            $this->w(6, 'awaiting_receipt', '2025-12-20'),
            $this->w(7, 'awaiting_receipt', '2026-03-01'),
        ]);
        $this->assertSame([2, 5, 1, 6, 7, 4, 3], Writ::boardSort($cards, $asOf)->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_finalized_most_recent_receipt_first(): void
    {
        $cards = collect([
            $this->w(1, 'finalized', '2024-01-01', 12, '2025-03-01'),
            $this->w(2, 'finalized', '2024-01-01', 12, '2026-10-01'),
            $this->w(3, 'finalized', '2024-01-01', 12, null),
            $this->w(4, 'finalized', '2024-01-01', 12, '2025-12-15'),
        ]);
        $this->assertSame([2, 4, 1, 3], Writ::boardSort($cards)->pluck('id')->all());
    }
}

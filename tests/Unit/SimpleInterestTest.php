<?php

namespace Tests\Unit;

use App\Support\SimpleInterest;
use PHPUnit\Framework\TestCase;

class SimpleInterestTest extends TestCase
{
    public function test_examples_and_allocation_order(): void
    {
        $this->assertSame(6000, SimpleInterest::interestCents(100_000, 2.0, 90));
        $this->assertSame(3000, SimpleInterest::penaltyCents(100_000, 2.0) + SimpleInterest::interestCents(100_000, 1.0, 30));
        $this->assertSame(0, SimpleInterest::interestCents(100_000, 0, 90));
        $split = SimpleInterest::allocate(50_00, 100_000, 10_00, 20_00);
        $this->assertSame(20_00, $split['penalty']);
        $this->assertSame(10_00, $split['interest']);
        $this->assertSame(20_00, $split['principal']);
    }

    public function test_segment_interest_after_partial_principal_payment(): void
    {
        // 1000 @ 2% a.m.: day 0-45 on 1000, day 45-90 on 500 → 30 + 15 = 45
        $balance = SimpleInterest::balance(
            100_000,
            '2026-01-01',
            null,
            2.0,
            0,
            0,
            '2026-04-01',
            [['date' => '2026-02-15', 'principal' => 50_000, 'interest' => 0, 'penalty' => 0]],
        );
        $this->assertSame(50_000, $balance['principal_open']);
        $this->assertSame(4500, $balance['interest_accrued']);
    }
}

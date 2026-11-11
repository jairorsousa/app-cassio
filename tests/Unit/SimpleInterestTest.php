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

    public function test_compound_interest_matches_agreed_formula(): void
    {
        // 1000 a 2% a.m. por 90 dias: P*((1,02)^3 − 1) = 61,208 → R$ 61,21
        $this->assertSame(6121, SimpleInterest::interestCents(100_000, 2.0, 90, SimpleInterest::TYPE_COMPOUND));
        // 30 dias (n = 1): compostos = simples
        $this->assertSame(2000, SimpleInterest::interestCents(100_000, 2.0, 30, SimpleInterest::TYPE_COMPOUND));
        $this->assertSame(2000, SimpleInterest::interestCents(100_000, 2.0, 30, SimpleInterest::TYPE_SIMPLE));
        // Sem tipo (ou inválido) continua simples, então totais antigos não mudam
        $this->assertSame(6000, SimpleInterest::interestCents(100_000, 2.0, 90));
        $this->assertSame(6000, SimpleInterest::interestCents(100_000, 2.0, 90, 'nope'));
    }

    public function test_compound_balance_and_late_interest_use_their_types(): void
    {
        $compound = SimpleInterest::balance(100_000, '2026-01-01', null, 2.0, 0, 0, '2026-04-01', [], SimpleInterest::TYPE_COMPOUND);
        $this->assertSame(6121, $compound['interest_accrued']);
        $this->assertSame(106121, $compound['balance']);

        $simple = SimpleInterest::balance(100_000, '2026-01-01', null, 2.0, 0, 0, '2026-04-01', [], SimpleInterest::TYPE_SIMPLE);
        $this->assertSame(6000, $simple['interest_accrued']);
        $this->assertSame(106000, $simple['balance']);

        // mora composta 1% a.m. por 30 dias sobre 1000, sem juros combinados
        $late = SimpleInterest::balance(100_000, '2026-01-01', '2026-01-01', 0, 0, 1, '2026-01-31', [], SimpleInterest::TYPE_SIMPLE, SimpleInterest::TYPE_COMPOUND);
        $this->assertSame(1000, $late['interest_open']); // n=1, igual ao simples
        $late90 = SimpleInterest::balance(100_000, '2026-01-01', '2026-01-01', 0, 0, 1, '2026-04-01', [], SimpleInterest::TYPE_SIMPLE, SimpleInterest::TYPE_COMPOUND);
        $this->assertSame((int) round(100_000 * (pow(1.01, 3) - 1)), $late90['interest_open']);
    }

    public function test_compound_segments_use_outstanding_principal(): void
    {
        // 45 dias sobre 1000 + 45 dias sobre 500, 2% a.m. compostos
        $first = SimpleInterest::interestCents(100_000, 2.0, 45, SimpleInterest::TYPE_COMPOUND);
        $second = SimpleInterest::interestCents(50_000, 2.0, 45, SimpleInterest::TYPE_COMPOUND);
        $balance = SimpleInterest::balance(
            100_000,
            '2026-01-01',
            null,
            2.0,
            0,
            0,
            '2026-04-01',
            [['date' => '2026-02-15', 'principal' => 50_000, 'interest' => 0, 'penalty' => 0]],
            SimpleInterest::TYPE_COMPOUND,
        );
        $this->assertSame($first + $second, $balance['interest_accrued']);
        $this->assertSame(50_000, $balance['principal_open']);
    }

    public function test_preview_totals_compare_both_types(): void
    {
        $preview = SimpleInterest::previewTotals(100_000, '2026-01-01', '2026-04-01', 2.0);
        $this->assertSame('até 01/04/2026', $preview['until_label']);
        $this->assertSame('R$ 1.060,00', $preview['simple']);
        $this->assertSame('R$ 1.061,21', $preview['compound']);

        $month = SimpleInterest::previewTotals(100_000, '2026-01-01', null, 2.0);
        $this->assertSame('em 30 dias', $month['until_label']);
        $this->assertSame('R$ 1.020,00', $month['simple']);
        $this->assertSame('R$ 1.020,00', $month['compound']);

        $late = SimpleInterest::previewLateTotals(100_000, 1.0);
        $this->assertSame('mora em 30 dias de atraso', $late['until_label']);
        $this->assertSame('R$ 10,00', $late['simple']);
        $this->assertSame('R$ 10,00', $late['compound']);
    }
}

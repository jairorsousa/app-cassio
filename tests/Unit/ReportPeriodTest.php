<?php

namespace Tests\Unit;

use App\Domains\Dashboard\Support\DashboardPreferences;
use App\Domains\Dashboard\Support\ReportPeriod;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    public function test_presets_cover_whole_months_and_position_is_capped_at_today(): void
    {
        $p = ReportPeriod::fromInput('this_month', null, null, '2026-11-05');
        $this->assertSame('2026-11-01', $p->start->toDateString());
        $this->assertSame('2026-11-30', $p->end->toDateString());
        $this->assertSame('2026-11-05', $p->positionDate()->toDateString());
        $this->assertTrue($p->isPositionToday());

        $p3 = ReportPeriod::fromInput('last_3', null, null, '2026-11-05');
        $this->assertSame('2026-09-01', $p3->start->toDateString());
        $this->assertSame(3, $p3->monthCount());
        $this->assertSame('2026-06-01', $p3->previous()->start->toDateString());
        $this->assertSame('2026-08-31', $p3->previous()->end->toDateString());

        $this->assertSame('2025-12-01', ReportPeriod::fromInput('last_12', null, null, '2026-11-05')->start->toDateString());
        $this->assertSame('2026-06-01', ReportPeriod::fromInput('last_6', null, null, '2026-11-05')->start->toDateString());
    }

    public function test_custom_range_uses_last_day_as_position_and_previous_same_length(): void
    {
        $p = ReportPeriod::fromInput('custom', '2026-10-20', '2026-10-10', '2026-11-05'); // invertido
        $this->assertSame('2026-10-10', $p->start->toDateString());
        $this->assertSame('2026-10-20', $p->end->toDateString());
        $this->assertSame('2026-10-20', $p->positionDate()->toDateString());
        $this->assertFalse($p->isPositionToday());
        $prev = $p->previous();
        $this->assertSame('2026-09-29', $prev->start->toDateString());
        $this->assertSame('2026-10-09', $prev->end->toDateString());

        $this->assertSame('this_month', ReportPeriod::fromInput('custom', 'x', '2026-13-40', '2026-11-05')->preset);
        $this->assertSame('this_month', ReportPeriod::fromInput('nada', null, null, '2026-11-05')->preset);
    }

    public function test_change_handles_division_by_zero(): void
    {
        $this->assertNull(ReportPeriod::change(100, 0)['pct']);
        $this->assertSame(100.0, ReportPeriod::change(100, 0)['diff']);
        $this->assertSame(-50.0, ReportPeriod::change(50, 100)['pct']);
        $this->assertSame(50.0, ReportPeriod::change(-50, -100)['pct']); // melhora de prejuízo
    }

    public function test_preferences_normalize_order_visibility_and_chart_types(): void
    {
        $n = DashboardPreferences::normalize(['order' => ['loans', 'nao_existe', 'loans', 'cash_position'], 'hidden' => ['accounts', 'x'], 'charts' => ['income_expense' => 'pie', 'expenses_by_category' => 'bar', 'own_result' => 'line']]);
        $this->assertSame(['loans', 'cash_position'], array_slice($n['order'], 0, 2));
        $this->assertCount(count(DashboardPreferences::WIDGETS), $n['order']);
        $this->assertSame(['accounts'], $n['hidden']);
        $this->assertSame(['expenses_by_category' => 'bar'], $n['charts']); // pizza não serve para série mensal; número não vira gráfico
        $this->assertSame('bar', DashboardPreferences::chartType($n, 'income_expense'));
        $this->assertNull(DashboardPreferences::chartType($n, 'own_result'));

        $moved = DashboardPreferences::move($n, 'cash_position', -1);
        $this->assertSame(['cash_position', 'loans'], array_slice($moved['order'], 0, 2));
        $this->assertSame($moved, DashboardPreferences::move($moved, 'cash_position', -1)); // já é o primeiro
        $this->assertSame('this_month', DashboardPreferences::normalize(null)['period']['preset']);
    }
}

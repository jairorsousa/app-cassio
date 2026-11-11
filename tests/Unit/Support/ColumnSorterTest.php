<?php

namespace Tests\Unit\Support;

use App\Support\ColumnSorter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ColumnSorterTest extends TestCase
{
    public function test_dates_sort_by_real_date_not_formatted_text(): void
    {
        $rows = [
            ['id' => 1, 'date' => Carbon::parse('2026-01-10'), 'label' => '10/01'],
            ['id' => 2, 'date' => Carbon::parse('2026-02-01'), 'label' => '01/02'],
            ['id' => 3, 'date' => Carbon::parse('2025-12-31'), 'label' => '31/12'],
        ];
        $asc = ColumnSorter::sort($rows, 'date', 'asc', ['date' => fn ($r) => $r['date']], [fn ($r) => $r['id']]);
        $this->assertSame([3, 1, 2], $asc->pluck('id')->all());
        $desc = ColumnSorter::sort($rows, 'date', 'desc', ['date' => fn ($r) => $r['date']], [[fn ($r) => $r['id'], 'desc']]);
        $this->assertSame([2, 1, 3], $desc->pluck('id')->all());
    }

    public function test_numbers_and_empty_values(): void
    {
        $rows = [
            ['id' => 1, 'amount' => 10],
            ['id' => 2, 'amount' => 2.5],
            ['id' => 3, 'amount' => null],
            ['id' => 4, 'amount' => 100],
        ];
        $asc = ColumnSorter::sort($rows, 'amount', 'asc', ['amount' => fn ($r) => $r['amount']], [fn ($r) => $r['id']]);
        $this->assertSame([3, 2, 1, 4], $asc->pluck('id')->all());
    }

    public function test_text_uses_pt_br_order(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Área'],
            ['id' => 2, 'name' => 'Zebra'],
            ['id' => 3, 'name' => 'amanda'],
            ['id' => 4, 'name' => ''],
        ];
        $asc = ColumnSorter::sort($rows, 'name', 'asc', ['name' => fn ($r) => $r['name']], [fn ($r) => $r['id']]);
        $this->assertSame(4, $asc->first()['id'], 'vazio vem primeiro no crescente');
        $names = $asc->skip(1)->pluck('name')->all();
        $this->assertSame(['amanda', 'Área', 'Zebra'], $names);
    }

    public function test_unknown_column_keeps_original_order(): void
    {
        $rows = [['id' => 2], ['id' => 1]];
        $out = ColumnSorter::sort($rows, 'missing', 'asc', ['id' => fn ($r) => $r['id']]);
        $this->assertSame([2, 1], $out->pluck('id')->all());
    }
}

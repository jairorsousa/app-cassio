<?php

namespace Tests\Unit;

use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BusinessDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_business_today_uses_fortaleza_after_21h_local(): void
    {
        // 22:30 em Fortaleza = 01:30 UTC do dia seguinte.
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 22, 30, 0, 'America/Fortaleza'));

        $this->assertSame('2026-11-06', Carbon::today()->toDateString(), 'app em UTC');
        $this->assertSame('2026-11-05', BusinessDate::today());

        $rule = ['d' => 'required|date|before_or_equal:'.BusinessDate::today()];
        $this->assertTrue(Validator::make(['d' => '2026-11-05'], $rule)->passes());
        $this->assertFalse(Validator::make(['d' => '2026-11-06'], $rule)->passes(), 'amanhã local continua bloqueado');
    }
}

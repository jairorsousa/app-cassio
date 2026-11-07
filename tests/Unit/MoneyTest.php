<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function values(): array
    {
        return [
            ['1234,56', '1234.56'], ['1.234,56', '1234.56'], ['R$ 1.234,56', '1234.56'], ["R$\u{00a0}1.234,56", '1234.56'],
            ['1234.56', '1234.56'], ['1,234.56', '1234.56'], ['1234', '1234.00'], ['1.234', '1234.00'],
            ['1.234.567,8', '1234567.80'], ['0,5', '0.50'], ['-1.234,56', '-1234.56'], ['', ''], ['abc', ''], [null, ''],
            ['100.000,00', '100000.00'], ['1234.567', '1234.57'],
        ];
    }

    #[DataProvider('values')]
    public function test_normalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, Money::normalize($input));
    }
}

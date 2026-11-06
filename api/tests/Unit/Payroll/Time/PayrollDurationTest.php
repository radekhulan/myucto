<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\PayrollDuration as Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollDurationTest extends TestCase
{
    public function testMinuteAndMillihourShareExactUnitsWithoutLoss(): void
    {
        self::assertSame(22500, Duration::parseHours('7:30'));
        self::assertSame(50, Duration::parseHours('0:01'));
        self::assertSame(3, Duration::parseHours('0,001'));
        self::assertSame(3003, Duration::parseHours('1.001'));
        self::assertSame(53, Duration::add(Duration::fromMinutes(1), Duration::fromMillihours(1)));
        self::assertNull(Duration::exactMinutes(53));
        self::assertNull(Duration::exactMillihours(53));
        self::assertSame(1, Duration::exactMinutes(50));
        self::assertSame(1, Duration::exactMillihours(3));
        self::assertSame(17, Duration::roundedMillihours(50));
        self::assertSame(18, Duration::roundedMillihours(53));
        self::assertSame(16, Duration::roundedMillihours(49));
    }

    #[DataProvider('invalidHours')]
    public function testInvalidHoursFailClosed(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Duration::parseHours($value);
    }

    public static function invalidHours(): iterable
    {
        foreach (['7:5', '7:60', '-1', '1.0001', '1,2.3', '', '1e3', 'NaN', '1 000'] as $value) {
            yield [$value];
        }
    }

    public function testOverflowIsRejectedBeforeIntegerBecomesFloat(): void
    {
        $this->expectException(\OverflowException::class);
        Duration::parseHours((string) PHP_INT_MAX);
    }
}

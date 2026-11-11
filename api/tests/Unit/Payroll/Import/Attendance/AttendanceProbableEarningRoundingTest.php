<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Hodinový výdělek z částky a hodin celočíselně v haléřích, polovina nahoru. */
final class AttendanceProbableEarningRoundingTest extends TestCase
{
    /** @return iterable<string,array{int,int,int}> */
    public static function cases(): iterable
    {
        yield '7 650 Kč za 42,5 h' => [765_000, 42_500, 18_000];
        yield '100 Kč za 3 h dolů' => [10_000, 3_000, 3_333];
        yield '200 Kč za 3 h nahoru' => [20_000, 3_000, 6_667];
        yield 'přesná polovina haléře nahoru' => [1, 2_000, 1];
        yield '1 Kč za 0,4 h' => [100, 400, 250];
    }

    #[DataProvider('cases')]
    public function testHourlyFromAmount(int $amountMinor, int $millihours, int $expected): void
    {
        self::assertSame($expected, AttendanceImportService::hourlyFromAmount($amountMinor, $millihours));
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSourceConfirmationRequired;
use MyInvoice\Service\Payroll\PayrollPeriodLabel;
use MyInvoice\Service\Payroll\Run\PayrollModuleAvailabilityMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollPeriodLabelTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function periods(): iterable
    {
        yield 'měsíc' => ['2026-02', '02/2026'];
        yield 'první den' => ['2026-03-01', '03/2026'];
        yield 'prosinec' => ['2025-12-31', '12/2025'];
        yield 'neznámý tvar' => ['únor', 'únor'];
    }

    #[DataProvider('periods')]
    public function testMonthYear(string $period, string $expected): void
    {
        self::assertSame($expected, PayrollPeriodLabel::monthYear($period));
    }

    public function testModuleStartMessageUsesMonthYear(): void
    {
        $message = PayrollModuleAvailabilityMessage::periodBeforeStart('2026-01-01', '2026-03-01');

        self::assertStringContainsString('Období 01/2026 předchází prvnímu mzdovému období, které vedete v MyÚčtu (03/2026)', $message);
        self::assertStringNotContainsString('2026-0', $message);
    }

    public function testAttendanceSourceConfirmationUsesMonthYear(): void
    {
        $exception = new AttendanceSourceConfirmationRequired([
            'period' => [
                'selected' => '2026-03',
                'detected' => [['period' => '2026-02', 'sources' => ['dochazka.xlsx']]],
                'mismatch' => true,
            ],
            'other_sources' => [['active_inputs' => 4]],
            'requires_confirmation' => true,
        ]);

        self::assertStringContainsString('hlásí k období 02/2026, vybrané je ale 03/2026', $exception->getMessage());
        self::assertStringContainsString('Období 03/2026 už má mzdové vstupy', $exception->getMessage());
        self::assertStringNotContainsString('2026-0', $exception->getMessage());
    }
}

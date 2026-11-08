<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollHourlyAverageEarningLink;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyAverageEarningLinkTest extends TestCase
{
    public function testLinkPrefillsEmploymentQuarterAndPeriod(): void
    {
        self::assertSame(
            '/payroll/absences?tab=averages&employment=42&year=2026&quarter=1&period=2026-02',
            PayrollHourlyAverageEarningLink::path(42, 2026, 1, '2026-02-01'),
        );
    }
}

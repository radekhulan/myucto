<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollHourlyAverageEarningLink;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
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

    /**
     * Náhrada pod hodinovým minimem: text radí opravit průměr, takže odkaz
     * vede do formuláře průměru za čtvrtletí měsíce, ne do podmínek vztahu.
     */
    public function testHourlyMinimumBlockerLeadsToTheAverageOfTheMonthQuarter(): void
    {
        self::assertSame(
            '/payroll/absences?tab=averages&employment=84&year=2026&quarter=2&period=2026-05',
            PayrollRunSnapshotBuilder::hourlyAverageMinimumLink('2026-05-01', 84),
        );
        self::assertSame(
            '/payroll/absences?tab=averages&employment=84&year=2027&quarter=4&period=2027-12',
            PayrollRunSnapshotBuilder::hourlyAverageMinimumLink('2027-12-01', 84),
        );
    }
}

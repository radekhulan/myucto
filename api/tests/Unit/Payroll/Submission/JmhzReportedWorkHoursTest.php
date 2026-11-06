<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReportedWorkHours;
use PHPUnit\Framework\TestCase;

/**
 * Pokyny MPSV k 10269: náhradní volno za přesčas čerpané v měsíci se od
 * přesčasu i od odpracovaných hodin odečte. Ve verzi 2026 se záporný přesčas
 * vykazuje nulou, ve verzi 2027 (slovník 1.5.0.3, „zápornost") se znaménkem.
 */
final class JmhzReportedWorkHoursTest extends TestCase
{
    public function testTimeOffAboveOvertimeIsSignedIn2027AndZeroIn2026(): void
    {
        $values = [
            'worked_millihours' => 152_000,
            'overtime_millihours' => 0,
            'compensatory_time_off_millihours' => 8_000,
        ];

        self::assertSame(
            ['worked_millihours' => 144_000, 'overtime_millihours' => -8_000],
            JmhzReportedWorkHours::reported($values, true),
        );
        self::assertSame(
            ['worked_millihours' => 144_000, 'overtime_millihours' => 0],
            JmhzReportedWorkHours::reported($values, false),
        );
    }

    /** Vzor z Pokynů: březen přesčas 8 h, fond 160 h → 10268 = 168, 10269 = 8. */
    public function testMonthWithoutTimeOffKeepsWorkedAndOvertime(): void
    {
        self::assertSame(
            ['worked_millihours' => 168_000, 'overtime_millihours' => 8_000],
            JmhzReportedWorkHours::reported(
                ['worked_millihours' => 168_000, 'overtime_millihours' => 8_000],
                true,
            ),
        );
    }

    /** Odpracované hodiny neklesnou pod kladný přesčas (kontrola 10268 ≥ 10269). */
    public function testWorkedHoursNeverDropBelowPositiveOvertime(): void
    {
        self::assertSame(
            ['worked_millihours' => 6_000, 'overtime_millihours' => 6_000],
            JmhzReportedWorkHours::reported(
                [
                    'worked_millihours' => 10_000,
                    'overtime_millihours' => 10_000,
                    'compensatory_time_off_millihours' => 4_000,
                ],
                true,
            ),
        );
    }
}

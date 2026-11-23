<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPrimaryEmployment;
use PHPUnit\Framework\TestCase;

final class JmhzPrimaryEmploymentTest extends TestCase
{
    public function testSoleSecondaryEmploymentBecomesPrimary(): void
    {
        self::assertSame(
            [0 => true],
            JmhzPrimaryEmployment::flags([['employment' => ['id' => 5, 'is_primary' => false]]]),
        );
    }

    public function testUnresolvedFlagIsNeverFilledIn(): void
    {
        self::assertSame(
            [0 => null],
            JmhzPrimaryEmployment::flags([['employment' => ['id' => 5, 'is_primary' => null]]]),
        );
    }

    public function testConcurrentEmploymentsKeepRecordedFlags(): void
    {
        self::assertSame(
            [0 => false, 1 => false],
            JmhzPrimaryEmployment::flags([
                ['employment' => ['id' => 5, 'is_primary' => false]],
                ['employment' => ['id' => 6, 'is_primary' => false]],
            ]),
        );
        self::assertSame(
            [0 => false, 1 => true],
            JmhzPrimaryEmployment::flags([
                ['employment' => ['id' => 5, 'is_primary' => false]],
                ['employment' => ['id' => 6, 'is_primary' => true]],
            ]),
        );
    }

    public function testSequentialPrimaryAgreementsHandOverToTheLatest(): void
    {
        self::assertSame(
            [0 => false, 1 => true],
            JmhzPrimaryEmployment::flags([
                ['employment' => ['id' => 5, 'is_primary' => true, 'start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'end_date' => '2026-04-15']],
                ['employment' => ['id' => 6, 'is_primary' => true, 'start_date' => '2026-04-16', 'actual_start_date' => null, 'end_date' => null]],
            ]),
        );
    }

    public function testOverlappingPrimaryAgreementsStayUnresolvedConflict(): void
    {
        self::assertSame(
            [0 => true, 1 => true],
            JmhzPrimaryEmployment::flags([
                ['employment' => ['id' => 5, 'is_primary' => true, 'start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'end_date' => '2026-04-16']],
                ['employment' => ['id' => 6, 'is_primary' => true, 'start_date' => '2026-04-16', 'actual_start_date' => '2026-04-16', 'end_date' => null]],
            ]),
        );
    }
}

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
}

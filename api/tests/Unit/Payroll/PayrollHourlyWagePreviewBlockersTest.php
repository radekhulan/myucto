<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollHourlyWagePreview;
use PHPUnit\Framework\TestCase;

/**
 * Náhled hodinové mzdy v rychlém vstupu opakoval tutéž blokaci za každý
 * úsek pracovní doby („Doložte příplatkové vrstvy…" čtyřikrát pod sebou).
 */
final class PayrollHourlyWagePreviewBlockersTest extends TestCase
{
    public function testSameBlockerFromSeveralSegmentsIsShownOnce(): void
    {
        $partition = ['code' => 'hourly_premium_partition_missing', 'message' => 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.'];
        $other = ['code' => 'hourly_time_not_approved', 'message' => 'Hodiny nejsou schválené.'];

        $unique = (new \ReflectionMethod(PayrollHourlyWagePreview::class, 'uniqueBlockers'))->invoke(null, [
            $partition + ['context' => ['segment' => 1]],
            $partition + ['context' => ['segment' => 2]],
            $other,
            $partition + ['context' => ['segment' => 3]],
            $partition + ['context' => ['segment' => 4]],
        ]);

        self::assertSame([
            $partition + ['context' => ['segment' => 1]],
            $other,
        ], $unique);
    }
}

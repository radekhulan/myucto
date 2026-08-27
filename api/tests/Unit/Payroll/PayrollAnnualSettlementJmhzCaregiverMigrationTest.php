<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollAnnualSettlementJmhzCaregiverMigrationTest extends TestCase
{
    public function testAddsAtomicAnnualCaregiverEvidence(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1578_payroll_annual_settlement_jmhz_caregivers.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'other_household_caregiver_status',
            $sql,
        );
        self::assertStringContainsString(
            "ENUM('unknown','none','present') NOT NULL DEFAULT 'unknown'",
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_annual_settlement_other_caregivers',
            $sql,
        );
        self::assertStringContainsString('months_mask', $sql);
        self::assertStringContainsString('CHAR(12) NOT NULL', $sql);
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, request_id)',
            $sql,
        );
        self::assertStringNotContainsString('PREPARE', $sql);
    }
}

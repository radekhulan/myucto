<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzEmployerAnnualEvidenceSpecMigrationTest extends TestCase
{
    public function testPinsTheOfficialSpecificationManifestOnEveryRevision(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1579_payroll_jmhz_employer_annual_spec.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);
        self::assertStringContainsString('spec_manifest_sha256', $sql);
        self::assertStringContainsString('CHAR(64) NOT NULL', $sql);
        self::assertStringContainsString(
            '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205',
            $sql,
        );
        $drop = strpos(
            $sql,
            'DROP TRIGGER IF EXISTS trg_payroll_jmhz_employer_annual_no_update',
        );
        $backfill = strpos($sql, 'UPDATE payroll_jmhz_employer_annual_evidence');
        $restore = strrpos(
            $sql,
            'CREATE TRIGGER trg_payroll_jmhz_employer_annual_no_update',
        );
        self::assertIsInt($drop);
        self::assertIsInt($backfill);
        self::assertIsInt($restore);
        self::assertLessThan($backfill, $drop);
        self::assertLessThan($restore, $backfill);
        self::assertStringNotContainsString('PREPARE', $sql);
    }
}

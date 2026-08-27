<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzEmployerAnnualEvidenceMigrationTest extends TestCase
{
    public function testEvidenceIsTenantScopedVersionedAndImmutable(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1577_payroll_jmhz_employer_annual_evidence.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_employer_annual_evidence',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_jmhz_employer_annual_revision',
            $sql,
        );
        self::assertStringContainsString('previous_revision_id', $sql);
        self::assertStringContainsString('ozp_reporting_office_id', $sql);
        self::assertStringContainsString('BEFORE UPDATE ON payroll_jmhz_employer_annual_evidence', $sql);
        self::assertStringContainsString('BEFORE DELETE ON payroll_jmhz_employer_annual_evidence', $sql);
        self::assertStringContainsString("'jmhz-preparation-source.v10'", $sql);
        self::assertStringNotContainsString('PREPARE', $sql);
    }
}

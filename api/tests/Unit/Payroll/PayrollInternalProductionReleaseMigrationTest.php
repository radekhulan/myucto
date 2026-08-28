<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollInternalProductionReleaseMigrationTest extends TestCase
{
    public function testCustomerQualificationStateReturnsToOrdinarySetupWithoutDeletingAuditHistory(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1610_payroll_internal_production_release.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            "SET status = 'setup'",
            $sql,
        );
        self::assertStringContainsString(
            "WHERE status = 'qualification_required'",
            $sql,
        );
        self::assertStringNotContainsString(
            'DROP TABLE payroll_production_qualifications',
            $sql,
        );
    }
}

<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollDependantJmhzIdentityMigrationTest extends TestCase
{
    public function testAddsExplicitNamesWithoutGuessingFromFullName(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1576_payroll_dependant_jmhz_identity.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS given_name VARCHAR(100) NULL',
            $sql,
        );
        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS family_name VARCHAR(100) NULL',
            $sql,
        );
        self::assertStringNotContainsString('UPDATE payroll_dependants', $sql);
        self::assertStringNotContainsString('PREPARE', $sql);
    }
}

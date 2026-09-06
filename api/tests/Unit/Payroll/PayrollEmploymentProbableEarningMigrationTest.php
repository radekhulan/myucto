<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

/**
 * Pravděpodobný výdělek (§ 355 ZP) v podmínkách pracovního vztahu.
 *
 * Migrace se pouští opakovaně (testy ji přehrávají nad existující DB), takže
 * musí být idempotentní. MariaDB neumí `ADD CONSTRAINT IF NOT EXISTS` u CHECK
 * spolehlivě napříč verzemi, proto se podmínka nejdřív zahazuje — kdyby se to
 * z migrace ztratilo, druhý běh spadne na duplicitní název.
 */
final class PayrollEmploymentProbableEarningMigrationTest extends TestCase
{
    public function testAddsNullableAmountWithMandatoryRationaleIdempotently(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4) . '/db/migrations/1751_payroll_employment_probable_earning.sql',
        );
        self::assertIsString($sql);

        foreach (['probable_hourly_earning_minor', 'probable_earning_rationale'] as $column) {
            self::assertStringContainsString("ADD COLUMN IF NOT EXISTS {$column}", $sql);
        }
        self::assertStringContainsString(
            'DROP CONSTRAINT IF EXISTS chk_payroll_employment_term_probable_earning',
            $sql,
        );
        self::assertStringContainsString(
            'ADD CONSTRAINT chk_payroll_employment_term_probable_earning',
            $sql,
        );
        // Částka je nepovinná, ale nikdy bez odůvodnění — § 355 odst. 2 ZP.
        self::assertStringContainsString('probable_hourly_earning_minor IS NULL', $sql);
        self::assertStringContainsString('CHAR_LENGTH(TRIM(probable_earning_rationale)) > 0', $sql);
        // Migrace nesmí nikomu nic dopisovat: pravděpodobný výdělek stanoví
        // zaměstnavatel, odvodit ho nelze.
        self::assertStringNotContainsString('UPDATE payroll_employment_terms', $sql);
    }
}

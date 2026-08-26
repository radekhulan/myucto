<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\TestCase;

final class PayrollEmployerPolicyMigrationTest extends TestCase
{
    public function testMigrationProtectsEffectiveHistoryAndAppendOnlyAudit(): void
    {
        $sql = file_get_contents(
            Bootstrap::rootDir()
                . '/db/migrations/1276_payroll_employer_policies.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_employer_policies',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_employer_policy_audit',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_employer_policy_overlap_insert',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_employer_policy_overlap_update',
            $sql,
        );
        self::assertStringContainsString(
            'NEW.row_version <= OLD.row_version',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_employer_policy_audit_update',
            $sql,
        );
        self::assertStringContainsString(
            'trg_payroll_employer_policy_audit_delete',
            $sql,
        );
    }

    public function testLeavePolicyMigrationAddsEffectiveAllowanceAndImmutableAutomaticSources(): void
    {
        $sql = file_get_contents(
            Bootstrap::rootDir()
                . '/db/migrations/1557_payroll_leave_policy_automation.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString('leave_entitlement_weeks', $sql);
        self::assertStringContainsString('leave_entitlement_weeks_override', $sql);
        self::assertStringContainsString("ENUM('manual_review','supported')", $sql);
        self::assertStringContainsString('calculation_mode', $sql);
    }
}

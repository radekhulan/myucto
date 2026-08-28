<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollOperationalReconciliationMigrationTest extends TestCase
{
    private string $sql;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1607_payroll_operational_reconciliation_issues.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);
        $this->sql = $sql;
    }

    public function testMigrationDefinesStableTenantIssueAndAuditHistory(): void
    {
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_operational_reconciliation_issues',
            $this->sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_operational_reconciliation_issue_key (',
            $this->sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_operational_reconciliation_issue_events',
            $this->sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, issue_id)',
            $this->sql,
        );
        self::assertStringContainsString(
            "ENUM('detected','observed','resolved','reopened')",
            $this->sql,
        );
    }

    public function testMigrationStoresExactMinorDifferenceAndImmutableSourceProof(): void
    {
        self::assertStringContainsString('expected_minor        BIGINT NULL', $this->sql);
        self::assertStringContainsString('actual_minor          BIGINT NULL', $this->sql);
        self::assertStringContainsString('difference_minor      BIGINT NULL', $this->sql);
        self::assertStringContainsString(
            'difference_minor = expected_minor - actual_minor',
            $this->sql,
        );
        self::assertStringContainsString(
            'source_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json))',
            $this->sql,
        );
        self::assertStringContainsString(
            "CHECK (source_hash REGEXP '^[0-9a-f]{64}$')",
            $this->sql,
        );
        self::assertStringContainsString('first_seen_at', $this->sql);
        self::assertStringContainsString('last_seen_at', $this->sql);
    }

    public function testConcurrencyContractUsesStableUniqueKeyAndRevisionLock(): void
    {
        self::assertStringContainsString(
            'supplier_id, run_id, issue_key',
            $this->sql,
        );
        $repository = file_get_contents(
            dirname(__DIR__, 3)
            . '/src/Repository/Payroll/PayrollOperationalReconciliationRepository.php',
        );
        self::assertIsString($repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('lockCurrentRevision(', $repository);
        self::assertStringContainsString('lockIssues(', $repository);
    }
}

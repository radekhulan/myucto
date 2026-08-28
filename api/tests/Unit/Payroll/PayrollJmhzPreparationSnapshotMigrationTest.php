<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollJmhzPreparationSnapshotMigrationTest extends TestCase
{
    public function testPreparationSnapshotIsTenantScopedEncryptedAndImmutable(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1360_payroll_jmhz_preparation_snapshots.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            "readiness_status          ENUM('blocked','source_ready')",
            $sql,
        );
        self::assertStringContainsString(
            "snapshot_ciphertext LIKE 'enc:v2:%'",
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_run_revisions (supplier_id, id, run_id)',
            $sql,
        );
        self::assertStringContainsString(
            'JMHZ preparation requires current approved revision',
            $sql,
        );
        self::assertSame(
            3,
            substr_count($sql, 'CREATE TRIGGER '),
        );
        self::assertSame(
            3,
            substr_count($sql, "SIGNAL SQLSTATE '45000'"),
        );
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
        self::assertStringNotContainsString('snapshot_json', $sql);
    }


    public function testIdempotencyClaimsAreDurableSingleAssignmentAliases(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1361_payroll_jmhz_preparation_idempotency_claims.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_preparation_idempotency_claims',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_jmhz_preparation_claim_scope',
            $sql,
        );
        self::assertStringContainsString(
            'JMHZ preparation idempotency claim is single-assignment',
            $sql,
        );
        self::assertSame(2, substr_count($sql, 'CREATE TRIGGER '));
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }

    public function testScenarioSelectorEvidenceIsAdditiveAndVersioned(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1362_payroll_jmhz_scenario_selector.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS jmhz_relationship_detail_code',
            $sql,
        );
        self::assertStringContainsString(
            "activity_code IN ('1','2','3','4','5','6','7','8','9')",
            $sql,
        );
        self::assertStringContainsString(
            "'jmhz-preparation-source.v1',",
            $sql,
        );
        self::assertStringContainsString(
            "'jmhz-preparation-source.v2'",
            $sql,
        );
        self::assertStringNotContainsString('UPDATE ', $sql);
    }

    public function testMixedPreparationScopeIsAdditiveAndKeepsLegacySnapshots(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1591_payroll_jmhz_mixed_preparation_snapshot.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS scenario_set_json', $sql);
        self::assertStringContainsString("scenario_key IN ('scenario_1', 'mixed')", $sql);
        self::assertStringContainsString("'jmhz-preparation-source.v11'", $sql);
        self::assertStringNotContainsString('UPDATE ', $sql);
    }
}

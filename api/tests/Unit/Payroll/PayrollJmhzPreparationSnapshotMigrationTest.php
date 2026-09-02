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

    /**
     * Výklad nevyplněného „ano/ne" jako „ne" přidal do snímku
     * `jmhz_default_interpretations`, tedy nový tvar — v12. Starší snímky
     * zůstávají a čtou se dál, migrace jen rozšiřuje výčet verzí.
     */
    public function testDefaultTristateInterpretationOnlyWidensBuilderVersions(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1728_payroll_jmhz_default_tristate_interpretation.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString("'jmhz-preparation-source.v11'", $sql);
        self::assertStringContainsString("'jmhz-preparation-source.v12'", $sql);
        self::assertStringContainsString(
            'DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder',
            $sql,
        );
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DELETE ', $sql);
    }

    /**
     * Jmenná větev `identifikaceType` dovolila snímku nést `null` místo OIČ a
     * ID PPV, tedy nový tvar — v13. Migrace zase jen rozšiřuje výčet verzí,
     * uložené snímky nepřepisuje.
     */
    public function testIdentityNameBranchOnlyWidensBuilderVersions(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
            . '/db/migrations/1729_payroll_jmhz_identity_name_branch.sql',
        );
        self::assertIsString($sql);
        self::assertStringContainsString("'jmhz-preparation-source.v12'", $sql);
        self::assertStringContainsString("'jmhz-preparation-source.v13'", $sql);
        self::assertStringContainsString(
            'DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_preparation_builder',
            $sql,
        );
        self::assertStringNotContainsString('UPDATE ', $sql);
        self::assertStringNotContainsString('DELETE ', $sql);
    }
}

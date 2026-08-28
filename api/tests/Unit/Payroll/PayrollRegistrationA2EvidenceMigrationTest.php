<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationA2EvidenceMigrationTest extends TestCase
{
    private function sql(): string
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
                . '/db/migrations/1608_payroll_registration_a2_evidence_ledger.sql',
        );
        self::assertIsString($sql);
        return $sql;
    }

    public function testLedgerIsImmutableTenantScopedAndBoundToTheA2Event(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_registration_a2_evidence_ledger',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_registration_a2_evidence_event',
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_registration_event_snapshots (supplier_id, id)',
            $sql,
        );
        self::assertStringContainsString(
            "event.interaction_code = 'termination'",
            $sql,
        );
        self::assertStringContainsString(
            'payroll_registration_a2_evidence_ledger is immutable',
            $sql,
        );
        self::assertStringContainsString(
            'payroll_registration_a2_evidence_ledger is append-only',
            $sql,
        );
    }

    public function testOnlyCanonicalAcceptedPlansCanBeFrozen(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            "schema_reference = _ascii'payroll-registration-a2-jmhz-evidence.v1'",
            $sql,
        );
        self::assertStringContainsString(
            "policy_reference = _ascii'regzec-a2-retroactive-jmhz-acceptance.v1'",
            $sql,
        );
        self::assertStringContainsString(
            'plan_sha256 = CONVERT(SHA2(plan_json, 256) USING ascii) COLLATE ascii_bin',
            $sql,
        );
        self::assertStringContainsString("IF NEW.decision <> 'accepted'", $sql);
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }
}


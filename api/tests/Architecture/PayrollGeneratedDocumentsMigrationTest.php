<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollGeneratedDocumentsMigrationTest extends TestCase
{
    public function testMigrationKeepsArtifactsTenantScopedAndAppendOnly(): void
    {
        $schema = file_get_contents(
            dirname(__DIR__, 3) . '/db/migrations/1230_payroll_generated_documents.sql',
        );
        $triggers = file_get_contents(
            dirname(__DIR__, 3) . '/db/migrations/1231_payroll_generated_documents_immutable.sql',
        );
        self::assertIsString($schema);
        self::assertIsString($triggers);

        self::assertStringContainsString('supplier_id', $schema);
        self::assertStringContainsString('revision_id', $schema);
        self::assertStringContainsString('source_snapshot_hash', $schema);
        self::assertStringContainsString('revision_snapshot_hash', $schema);
        self::assertStringContainsString('employee_scope_id', $schema);
        self::assertStringContainsString('file_sha256', $schema);
        self::assertStringContainsString('idempotency_key_hash', $schema);
        self::assertStringContainsString('token_hash', $schema);
        self::assertStringContainsString('Generated payroll documents are immutable', $triggers);
        self::assertStringContainsString('Payroll document requires an approved matching revision', $triggers);

        $dms = file_get_contents(
            dirname(__DIR__, 3) . '/db/migrations/1233_payroll_document_dms_links.sql',
        );
        self::assertIsString($dms);
        self::assertStringContainsString('Payroll DMS link tenant mismatch', $dms);
        self::assertStringContainsString('Payroll DMS links are append-only', $dms);

        $scope = file_get_contents(
            dirname(__DIR__, 3) . '/db/migrations/1234_payroll_document_employee_scope.sql',
        );
        self::assertIsString($scope);
        self::assertStringContainsString('COALESCE(employee_id, 0)', $scope);
        self::assertStringContainsString('employee_scope_id', $scope);

        $delivery = file_get_contents(
            dirname(__DIR__, 3) . '/db/migrations/1590_payroll_document_delivery_ledger.sql',
        );
        self::assertIsString($delivery);
        self::assertStringContainsString('payroll_document_delivery_events', $delivery);
        self::assertStringContainsString('Payroll document delivery tenant or person mismatch', $delivery);
        self::assertStringContainsString('Payroll document delivery events are append-only', $delivery);
        self::assertStringNotContainsString('token_hash', $delivery);
    }

    public function testCleanupPurgesExpiredDownloadGrants(): void
    {
        $cleanup = file_get_contents(
            dirname(__DIR__, 3) . '/api/bin/cron-cleanup.php',
        );
        self::assertIsString($cleanup);
        self::assertStringContainsString(
            'DELETE FROM payroll_document_download_grants',
            $cleanup,
        );
        self::assertStringContainsString(
            "\$report['payroll_download_grants']",
            $cleanup,
        );
    }
}

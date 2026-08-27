<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollStatutoryObligationMigrationTest extends TestCase
{
    public function testEvidenceIsTenantScopedIdempotentAndImmutable(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1588_payroll_statutory_obligation_evidence.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_statutory_obligation_evidence',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_stat_obligation_evidence_idempotency',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'document.supplier_id = NEW.supplier_id',
            $sql,
        );
        self::assertStringContainsString("document.scope = 'company'", $sql);
        self::assertStringContainsString('document_sha256', $sql);
        self::assertStringContainsString('capability_matrix_sha256', $sql);
        self::assertStringContainsString(
            "ENUM('NEMPRI','HZUPN','STATUTORY_ACCIDENT_INSURANCE')",
            $sql,
        );
        self::assertStringContainsString('payment_amount_minor > 0', $sql);
        self::assertStringContainsString("payment_currency = 'CZK'", $sql);
        self::assertStringContainsString(
            'Payroll statutory obligation evidence is immutable',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll statutory obligation evidence is append-only',
            $sql,
        );
    }
}

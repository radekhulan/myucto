<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollForeignPermitMigrationTest extends TestCase
{
    public function testForeignPermitEvidenceIsTenantScopedAppendOnlyAndDoesNotReplaceTriggers(): void
    {
        $path = dirname(__DIR__, 3) . '/db/migrations/1598_payroll_foreign_permits.sql';
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString('document_supplier_id', $sql);
        self::assertStringContainsString('CHECK (document_supplier_id = supplier_id)', $sql);
        self::assertStringContainsString('FOREIGN KEY (document_supplier_id, document_id)', $sql);
        self::assertStringContainsString('REFERENCES documents (supplier_id, id)', $sql);
        self::assertStringContainsString('FOREIGN KEY (supplier_id, supersedes_permit_id)', $sql);
        self::assertStringContainsString(
            'REFERENCES payroll_person_foreign_permits (supplier_id, id)',
            $sql,
        );
        self::assertStringContainsString('CREATE TRIGGER IF NOT EXISTS', $sql);
        self::assertStringNotContainsString('DROP TRIGGER', $sql);
        self::assertStringContainsString('NEW.effective_from > predecessor.effective_from', $sql);
        self::assertStringNotContainsString('INSERT INTO payroll_person_foreign_permits', $sql);

        $hardening = file_get_contents(dirname(__DIR__, 3) . '/db/migrations/1599_payroll_foreign_permit_hardening.sql');
        self::assertIsString($hardening);
        self::assertStringContainsString('ADD UNIQUE KEY IF NOT EXISTS uq_payroll_foreign_permit_predecessor', $hardening);
        self::assertStringContainsString('document_supplier_id', $hardening);
    }
}

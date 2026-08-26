<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Regzel\RegzelTaxOfficeCode;
use PHPUnit\Framework\TestCase;

final class PayrollRegzelMigrationTest extends TestCase
{
    public function testRegzelSnapshotsAreTenantScopedEncryptedAndImmutable(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1284_payroll_regzel_backend_core.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_regzel_employer_profiles',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_regzel_payload_snapshots',
            $sql,
        );
        self::assertStringContainsString(
            "environment              ENUM('production','test')",
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_regzel_snapshot_idempotency',
            $sql,
        );
        self::assertStringContainsString(
            'supplier_id, environment, idempotency_key_hash',
            $sql,
        );
        self::assertStringContainsString('snapshot_ciphertext', $sql);
        self::assertStringNotContainsString('snapshot_plaintext', $sql);
        self::assertStringContainsString('source_snapshot_hash', $sql);
        self::assertStringContainsString('xml_sha256', $sql);
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, office_id)',
            $sql,
        );
        self::assertStringContainsString(
            'Payroll REGZEL payload snapshots are immutable',
            $sql,
        );
    }

    public function testRegzelIdentifiersStaySeparateFromLegacyCompanyFields(): void
    {
        $root = dirname(__DIR__, 3) . '/db/migrations/';
        $taxOffice = (string) file_get_contents(
            $root . '1546_payroll_regzel_tax_office_codes.sql',
        );
        $payerReference = (string) file_get_contents(
            $root . '1547_payroll_regzel_payer_reference_number.sql',
        );
        $taxOfficePair = (string) file_get_contents(
            $root . '1548_payroll_regzel_tax_office_pair.sql',
        );
        $taxOfficeCodebook = (string) file_get_contents(
            $root . '1549_payroll_regzel_tax_office_codebook.sql',
        );

        self::assertStringContainsString('tax_office_code', $taxOffice);
        self::assertStringContainsString('tax_office_workplace_code', $taxOffice);
        self::assertStringNotContainsString('financial_office_code =', $taxOffice);
        self::assertStringContainsString('payer_reference_number', $payerReference);
        self::assertStringContainsString("REGEXP '^6[0-9]{8}$'", $payerReference);
        self::assertStringNotContainsString('employer_registration_number', $payerReference);
        self::assertStringContainsString("tax_office_code = '4000'", $taxOfficePair);
        self::assertStringContainsString(
            'tax_office_workplace_code IS NOT NULL',
            $taxOfficePair,
        );
        self::assertStringContainsString("'2000', '2100', '2200', '2300'", $taxOfficePair);
        self::assertStringNotContainsString("'7000'", $taxOfficePair);
        self::assertStringContainsString("WHEN '2300'", $taxOfficeCodebook);
        self::assertStringContainsString("'2301','2302','2303'", $taxOfficeCodebook);
        self::assertStringContainsString(
            "WHEN '4000' THEN tax_office_workplace_code IS NULL",
            $taxOfficeCodebook,
        );

        preg_match_all(
            "/WHEN '(\d{4})' THEN tax_office_workplace_code IN \((.*?)\)/s",
            $taxOfficeCodebook,
            $matches,
            PREG_SET_ORDER,
        );
        $migrationPairs = [];
        foreach ($matches as $match) {
            preg_match_all("/'(\d{4})'/", $match[2], $workplaceMatches);
            $migrationPairs[$match[1]] = $workplaceMatches[1];
        }
        $runtimePairs = [];
        foreach (RegzelTaxOfficeCode::officeCodes() as $officeCode) {
            if ($officeCode !== '4000') {
                $runtimePairs[$officeCode] =
                    RegzelTaxOfficeCode::workplacesForOffice($officeCode);
            }
        }
        self::assertSame($runtimePairs, $migrationPairs);
    }
}

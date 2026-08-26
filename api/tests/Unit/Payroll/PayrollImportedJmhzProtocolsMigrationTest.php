<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

/**
 * Načtené protokoly mají VLASTNÍ tabulku, ne řádek v ledgeru pokusů.
 *
 * Ledger 1372 je důkaz o odeslání, které jsme udělali my: `submission_id` je
 * povinný cizí klíč do `payroll_submissions`, trigger vynucuje shodu kanálu
 * a idempotenční klíč i otisk požadavku jsou povinné. Protokol k podání
 * odeslanému cizím softwarem by se tam vešel jen s vymyšleným podáním a
 * vymyšlenými otisky — tedy jako falešný důkaz.
 */
final class PayrollImportedJmhzProtocolsMigrationTest extends TestCase
{
    private function sql(): string
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1375_payroll_jmhz_imported_protocols.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }

    public function testTableIsTenantAndEnvironmentScoped(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_imported_jmhz_protocols',
            $sql,
        );
        self::assertStringContainsString(
            "environment           ENUM('production','test') NOT NULL",
            $sql,
        );
        self::assertStringContainsString(
            'uq_payroll_imported_protocols_environment_id',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT',
            $sql,
        );
        self::assertStringNotContainsString('ON DELETE CASCADE', $sql);
    }

    /**
     * Neověřitelný doklad se do evidence nesmí dostat vůbec — proto je
     * variabilní symbol NOT NULL, ne „vyplní se, když je".
     */
    public function testVariableSymbolIsMandatory(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'variable_symbol       VARCHAR(10) CHARACTER SET ascii'
                . ' COLLATE ascii_bin NOT NULL',
            $sql,
        );
        self::assertStringContainsString('chk_payroll_imported_protocols_vs', $sql);
    }

    /** Druhé načtení téhož protokolu má řádek přepsat, ne zdvojit. */
    public function testReimportIsIdempotentByDedupeKey(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            "UNIQUE KEY uq_payroll_imported_protocols_dedupe\n    (supplier_id, environment, dedupe_key)",
            $sql,
        );
    }

    public function testIdentityOfAnImportedDocumentIsImmutable(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'imported protocol identity is immutable',
            $sql,
        );
        self::assertStringContainsString(
            'imported protocol row_version must advance by one',
            $sql,
        );
    }

    public function testOriginalXmlAndItsFingerprintAreStored(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString('payload_xml           MEDIUMTEXT NOT NULL', $sql);
        self::assertStringContainsString('payload_sha256', $sql);
        self::assertStringContainsString('chk_payroll_imported_protocols_payload', $sql);
        // Šest doložených stavů hlášení; sedmý by znamenal dopočítaný stav.
        self::assertStringContainsString('status_code BETWEEN 1 AND 6', $sql);
    }

    public function testVerifiedFormOutcomesHaveAnImmutableReceiptScopedLedger(): void
    {
        $path = dirname(__DIR__, 4)
            . '/db/migrations/1550_payroll_jmhz_protocol_form_outcomes.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_jmhz_protocol_form_outcomes',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_jmhz_form_outcome_receipt_form',
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_submission_receipts',
            $sql,
        );
        self::assertStringContainsString(
            'REFERENCES payroll_submission_artifacts',
            $sql,
        );
        self::assertStringContainsString('errors_ciphertext', $sql);
        self::assertStringContainsString("errors_ciphertext LIKE 'enc:v2:%'", $sql);
        self::assertStringContainsString('protocol_status_code IS NULL', $sql);
        self::assertStringContainsString('jmhz protocol form outcomes are immutable', $sql);
    }
}

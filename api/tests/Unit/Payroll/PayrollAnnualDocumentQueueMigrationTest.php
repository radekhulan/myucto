<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

/**
 * Roční fronta dokumentů má VLASTNÍ tabulky, ne rozšířené `payroll_document_batches`.
 *
 * Měsíční fronta drží výplatní pásky u zdroje dvěma spouštěči a nenulovatelnými
 * cizími klíči na běh a revizi. Roční dokumenty žádnou revizi běhu nemají, takže
 * rozšíření by znamenalo uvolnit ty sloupce na NULL a oba spouštěče zahodit —
 * tenhle test hlídá, že se to nestalo.
 */
final class PayrollAnnualDocumentQueueMigrationTest extends TestCase
{
    private const MIGRATION = '/db/migrations/1652_payroll_annual_document_queue.sql';

    public function testAnnualQueueLivesInItsOwnTablesWithoutRunOrRevision(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_annual_document_batches',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_annual_document_batch_items',
            $sql,
        );
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_annual_document_batch_attempts',
            $sql,
        );
        // Roční rozsah je zdaňovací období. Kdyby se sem vloudil sloupec běhu
        // nebo revize, je to znamení, že se fronta zase zavěsila na měsíc.
        self::assertSame(0, preg_match('/^\s+run_id\s/m', $sql));
        self::assertSame(0, preg_match('/^\s+revision_id\s/m', $sql));
        self::assertStringContainsString('tax_year              SMALLINT UNSIGNED NOT NULL', $sql);
    }

    public function testMonthlyQueueKeepsItsRevisionGuards(): void
    {
        $monthly = $this->read('/db/migrations/1587_payroll_document_generation_queue.sql');

        self::assertStringContainsString('run_id                BIGINT UNSIGNED NOT NULL', $monthly);
        self::assertStringContainsString('revision_id           BIGINT UNSIGNED NOT NULL', $monthly);
        self::assertStringContainsString('trg_payroll_document_batch_source_insert', $monthly);
        self::assertStringContainsString('trg_payroll_document_batch_item_source_insert', $monthly);
    }

    public function testItemsCarryLeaseAttemptsAndSkippedOutcome(): void
    {
        $sql = $this->migration();

        // Přeskočení je vlastní KONEC, ne selhání: osoba už potvrzení za rok má
        // a jeho nahrazení je oprava s povinným důvodem.
        self::assertStringContainsString(
            "ENUM('queued','processing','retry_wait','failed','succeeded','skipped')",
            $sql,
        );
        self::assertStringContainsString('skipped_count', $sql);
        self::assertStringContainsString('lease_token           BINARY(16) NULL', $sql);
        self::assertStringContainsString('attempt_count         INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('chk_payroll_annual_document_batch_item_lease', $sql);
        self::assertStringContainsString('chk_payroll_annual_document_batch_item_result', $sql);
    }

    public function testTenantIsolationAndIdempotencyAreEnforcedByTheSchema(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_annual_document_batch_idempotency',
            $sql,
        );
        // Logický klíč rozsahu je schválně NEunikátní: po dokončení dávky smí
        // účetní tentýž rozsah spustit znovu.
        self::assertStringContainsString(
            'KEY idx_payroll_annual_document_batch_scope',
            $sql,
        );
        self::assertStringNotContainsString(
            'UNIQUE KEY idx_payroll_annual_document_batch_scope',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, batch_id)',
            $sql,
        );
        self::assertStringContainsString(
            'chk_payroll_annual_document_batch_year',
            $sql,
        );
    }

    /**
     * Migrace se v testech pouští znovu, takže musí být idempotentní — a runner
     * čte nebufferovaně, takže v ní nesmí být žádný dotaz vracející výsledek.
     */
    public function testMigrationIsReplayableAndFreeOfResultProducingStatements(): void
    {
        $sql = $this->migration();

        self::assertSame(
            3,
            substr_count($sql, 'CREATE TABLE IF NOT EXISTS'),
        );
        self::assertStringNotContainsString('CREATE TABLE payroll_annual', $sql);
        self::assertStringNotContainsString('SHOW ', $sql);
        self::assertStringNotContainsString('PREPARE ', $sql);
        self::assertStringNotContainsString('DELIMITER', $sql);
        self::assertSame(0, preg_match('/^\s*SELECT\s/mi', $sql));
    }

    private function migration(): string
    {
        return $this->read(self::MIGRATION);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}

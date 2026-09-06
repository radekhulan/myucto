<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronPreflight;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Brány mzdových workerů nad SQLite in-memory.
 *
 * Testuje se OBOJÍ směr, protože každý selhává jinak nebezpečně: falešné „mám
 * práci" stojí zbytečný bootstrap, kdežto falešné „nemám co dělat" frontu tiše
 * umlčí a účetní čeká u obrazovky na pásku, která se nikdy nevyrenderuje.
 * Prázdné tabulky zároveň dokazují, že se dotaz vůbec provede — {@see CronPreflight}
 * je fail-open, takže překlep v SQL by se jinak schoval za `true`.
 */
final class CronPreflightPayrollTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE payroll_document_batch_items (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE payroll_document_batches (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE payroll_annual_document_batch_items (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE payroll_document_access_links (id INTEGER PRIMARY KEY, dispatch_state TEXT NOT NULL, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE payroll_period_export_jobs (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
    }

    public function testEmptyQueuesReportNoWork(): void
    {
        self::assertFalse(CronPreflight::hasPayrollDocumentWork($this->pdo));
        self::assertFalse(CronPreflight::hasPayrollPeriodExportWork($this->pdo));
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function documentQueueRows(): iterable
    {
        yield 'čekající pásky' => ["INSERT INTO payroll_document_batch_items (status) VALUES ('queued')"];
        yield 'položka po spadlém workerovi' => ["INSERT INTO payroll_document_batch_items (status) VALUES ('processing')"];
        yield 'dávka čekající na ZIP' => ["INSERT INTO payroll_document_batches (status) VALUES ('running')"];
        yield 'roční doklady' => ["INSERT INTO payroll_annual_document_batch_items (status) VALUES ('retry_wait')"];
        yield 'neodeslaný odkaz' => ["INSERT INTO payroll_document_access_links (dispatch_state, revoked_at) VALUES ('pending', NULL)"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentQueueRows')]
    public function testAnyPendingDocumentQueueOpensTheGate(string $insert): void
    {
        $this->pdo->exec($insert);

        self::assertTrue(CronPreflight::hasPayrollDocumentWork($this->pdo));
    }

    public function testFinishedDocumentQueuesKeepTheGateClosed(): void
    {
        $this->pdo->exec("INSERT INTO payroll_document_batch_items (status) VALUES ('succeeded')");
        $this->pdo->exec("INSERT INTO payroll_document_batches (status) VALUES ('completed')");
        $this->pdo->exec("INSERT INTO payroll_annual_document_batch_items (status) VALUES ('skipped')");
        $this->pdo->exec("INSERT INTO payroll_document_access_links (dispatch_state, revoked_at) VALUES ('sent', NULL)");

        self::assertFalse(CronPreflight::hasPayrollDocumentWork($this->pdo));
    }

    /**
     * Zneplatněný odkaz se už nikdy neodešle — `claimNext()` ho vynechává, takže
     * ho nesmí ani brána brát jako důvod ke spuštění workeru.
     */
    public function testRevokedLinkIsNotWork(): void
    {
        $this->pdo->exec("INSERT INTO payroll_document_access_links (dispatch_state, revoked_at) VALUES ('pending', '2026-09-06 12:00:00')");

        self::assertFalse(CronPreflight::hasPayrollDocumentWork($this->pdo));
    }

    public function testQueuedPeriodExportOpensTheGate(): void
    {
        $this->pdo->exec("INSERT INTO payroll_period_export_jobs (status) VALUES ('queued')");

        self::assertTrue(CronPreflight::hasPayrollPeriodExportWork($this->pdo));
    }

    public function testCompletedPeriodExportKeepsTheGateClosed(): void
    {
        $this->pdo->exec("INSERT INTO payroll_period_export_jobs (status) VALUES ('completed')");

        self::assertFalse(CronPreflight::hasPayrollPeriodExportWork($this->pdo));
    }

    /**
     * Chybějící tabulka (instalace před migrací) musí bránu otevřít, ne zavřít.
     */
    public function testMissingTableFailsOpen(): void
    {
        $this->pdo->exec('DROP TABLE payroll_period_export_jobs');

        self::assertTrue(CronPreflight::hasPayrollPeriodExportWork($this->pdo));
    }
}

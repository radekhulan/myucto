<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\PayrollArchiveReencryptionService as Reencryption;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přešifrování nešifrovaných mzdových dokumentů z doby před W30 / C-05.
 *
 * Každý test si staví vlastní datový adresář (MYINVOICE_DATA_DIR → dočasný
 * adresář, cesty skládá RuntimePaths) a vlastní izolovanou firmu v transakci.
 */
#[Group('integration')]
final class PayrollArchiveReencryptionServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const RESULT_HASH = '1111111111111111111111111111111111111111111111111111111111111111';
    private const ANNUAL_HASH = '2222222222222222222222222222222222222222222222222222222222222222';

    private Connection $db;
    private PayrollDocumentStorage $storage;
    private PayrollDocumentKeyRing $keys;
    private Reencryption $service;
    private int $supplierId;
    private int $employeeId;
    private int $runId;
    private int $revisionId;
    private int $annualRevisionId;
    private int $sequence = 0;
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        foreach ([
            'payroll_document_data_keys',
            'payroll_generated_documents',
            'payroll_annual_document_revisions',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }

        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'myucto-archiv-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0750, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->seedAnchors();

        $this->keys = new PayrollDocumentKeyRing(
            $this->db,
            $container->get(SecretEncryption::class),
        );
        $this->storage = new PayrollDocumentStorage($this->keys);
        $this->service = new Reencryption($this->db, $this->storage, $this->keys);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->dataDir)) {
            $this->removeDirectory($this->dataDir);
            $this->previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        }
    }

    public function testEncryptsLegacyPlaintextVerifiesAndRemovesOriginal(): void
    {
        $bytes = '%PDF-1.4 synteticka paska 7001010009';
        $key = $this->writeLegacy($bytes);
        $this->insertDocument($key, $this->employeeId);

        self::assertSame(1, $this->service->countLegacy($this->supplierId)['total']);

        $report = $this->service->run($this->supplierId, false);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_ENCRYPTED], json_encode($report));
        self::assertSame([$this->employeeId], $report['items'][0]['subjects']);
        self::assertFileDoesNotExist($this->legacyPath($key));
        $encrypted = $this->subjectPath($key, $this->employeeId);
        self::assertFileExists($encrypted);
        $raw = (string) file_get_contents($encrypted);
        self::assertStringNotContainsString('7001010009', $raw);
        self::assertStringNotContainsString('%PDF', $raw);
        self::assertSame(
            $bytes,
            $this->storage->readEncryptedVerified($this->supplierId, $key, $this->employeeId),
        );
        self::assertSame(
            $bytes,
            $this->storage->readVerified($this->supplierId, $key, $this->employeeId),
        );
        self::assertSame(0, $this->service->countLegacy($this->supplierId)['total']);
    }

    /**
     * Ověření zapsané kopie nesmí spadnout do legacy větve: kdyby četlo
     * originál, „prošlo" by i tehdy, když se kopie vůbec nezapsala.
     */
    public function testEncryptedReadNeverFallsBackToLegacyPlaintext(): void
    {
        $bytes = '%PDF-1.4 jen legacy';
        $key = $this->writeLegacy($bytes);
        self::assertSame($bytes, $this->storage->readVerified($this->supplierId, $key, $this->employeeId));

        $this->expectException(\RuntimeException::class);
        $this->storage->readEncryptedVerified($this->supplierId, $key, $this->employeeId);
    }

    public function testDryRunTouchesNothing(): void
    {
        $key = $this->writeLegacy('%PDF-1.4 dry');
        $this->insertDocument($key, $this->employeeId);

        $report = $this->service->run($this->supplierId, true);

        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['counts'][Reencryption::STATUS_WOULD_ENCRYPT]);
        self::assertFileExists($this->legacyPath($key));
        self::assertFileDoesNotExist($this->subjectPath($key, $this->employeeId));
        self::assertFalse(
            $this->keyExists($this->employeeId),
            'Dry-run nesmí zakládat ani datový klíč.',
        );
    }

    public function testSecondRunIsNoOp(): void
    {
        $key = $this->writeLegacy('%PDF-1.4 idem');
        $this->insertDocument($key, $this->employeeId);

        $this->service->run($this->supplierId, false);
        $second = $this->service->run($this->supplierId, false);

        self::assertSame(0, $second['processed']);
        self::assertSame([], $second['items']);
    }

    /**
     * Originál se smí smazat až po ověření zapsané kopie. Na cílové cestě tu
     * leží poškozený soubor, takže kopie ověřením neprojde - plaintext musí
     * zůstat a položka skončí jako selhání, ne jako „hotovo".
     */
    public function testOriginalSurvivesWhenEncryptedCopyCannotBeVerified(): void
    {
        $bytes = '%PDF-1.4 poskozena kopie';
        $key = $this->writeLegacy($bytes);
        $this->insertDocument($key, $this->employeeId);
        $target = $this->subjectPath($key, $this->employeeId);
        mkdir(dirname($target), 0750, true);
        file_put_contents($target, 'pdoc:v1:' . str_repeat("\0", 40));

        $report = $this->service->run($this->supplierId, false);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_FAILED], json_encode($report));
        self::assertArrayHasKey('error', $report['items'][0]);
        self::assertFileExists($this->legacyPath($key));
        self::assertSame($bytes, file_get_contents($this->legacyPath($key)));
    }

    public function testContentNotMatchingItsNameIsLeftForManualReview(): void
    {
        $key = hash('sha256', 'original obsah');
        $path = $this->legacyPath($key);
        mkdir(dirname($path), 0750, true);
        file_put_contents($path, 'podvrzeny obsah');
        $this->insertDocument($key, $this->employeeId);

        $report = $this->service->run($this->supplierId, false);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_INTEGRITY_MISMATCH]);
        self::assertSame('podvrzeny obsah', file_get_contents($path));
        self::assertFileDoesNotExist($this->subjectPath($key, $this->employeeId));
    }

    public function testOrphanIsReportedAndEncryptedUnderCompanyKeyOnlyOnRequest(): void
    {
        $bytes = '%PDF-1.4 sirotek';
        $key = $this->writeLegacy($bytes);

        $skipped = $this->service->run($this->supplierId, false);
        self::assertSame(1, $skipped['counts'][Reencryption::STATUS_ORPHAN_SKIPPED]);
        self::assertFileExists($this->legacyPath($key));

        $encrypted = $this->service->run($this->supplierId, false, true);
        self::assertSame(1, $encrypted['counts'][Reencryption::STATUS_ENCRYPTED]);
        self::assertSame([PayrollDocumentKeyRing::COMPANY_SUBJECT_ID], $encrypted['items'][0]['subjects']);
        self::assertFileDoesNotExist($this->legacyPath($key));
        self::assertSame(
            $bytes,
            $this->storage->readEncryptedVerified(
                $this->supplierId,
                $key,
                PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
            ),
        );
    }

    public function testCompanyDocumentGoesUnderCompanyKey(): void
    {
        $key = $this->writeLegacy('%PDF-1.4 rekapitulace');
        $this->insertDocument($key, null);

        $report = $this->service->run($this->supplierId, false);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_ENCRYPTED]);
        self::assertFileExists($this->subjectPath($key, PayrollDocumentKeyRing::COMPANY_SUBJECT_ID));
    }

    /**
     * Po krypto-výmazu nesmí obsah osoby vzniknout znovu v čitelné ani
     * šifrované podobě. Plaintext se jen vykáže; smazat ho smí až výslovná
     * volba, protože jde o nevratný krok bez kopie.
     */
    public function testErasedSubjectIsNeverReencryptedAndPurgedOnlyWithFlag(): void
    {
        $this->storage->store($this->supplierId, '%PDF-1.4 novy', null, $this->employeeId);
        $this->keys->destroy($this->supplierId, $this->employeeId, null, 'test');
        $key = $this->writeLegacy('%PDF-1.4 stary vymazane osoby');
        $this->insertDocument($key, $this->employeeId);

        $report = $this->service->run($this->supplierId, false);
        self::assertSame(1, $report['counts'][Reencryption::STATUS_ERASED_SKIPPED]);
        self::assertFileExists($this->legacyPath($key));
        self::assertFileDoesNotExist($this->subjectPath($key, $this->employeeId));

        $dry = $this->service->run($this->supplierId, true, false, true);
        self::assertSame(1, $dry['counts'][Reencryption::STATUS_WOULD_PURGE]);
        self::assertFileExists($this->legacyPath($key));

        $purged = $this->service->run($this->supplierId, false, false, true);
        self::assertSame(1, $purged['counts'][Reencryption::STATUS_ERASED_PURGED]);
        self::assertFileDoesNotExist($this->legacyPath($key));
        self::assertFileDoesNotExist($this->subjectPath($key, $this->employeeId));
    }

    public function testLimitProcessesBatchAndReportsRemaining(): void
    {
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->insertDocument(
                $this->writeLegacy('%PDF-1.4 davka ' . $suffix),
                $this->employeeId,
            );
        }

        $first = $this->service->run($this->supplierId, false, false, false, 2);
        self::assertSame(2, $first['processed']);
        self::assertSame(1, $first['remaining']);

        $second = $this->service->run($this->supplierId, false, false, false, 2);
        self::assertSame(1, $second['processed']);
        self::assertSame(0, $second['remaining']);
        self::assertSame(0, $this->service->countLegacy($this->supplierId)['total']);
    }

    /**
     * Přeskočené soubory (osiřelé) nesmí spotřebovat dávku. Jinak by dávkování
     * z Diagnostiky stálo na týchž souborech a k ostatním se nikdy nedostalo.
     */
    public function testSkippedFilesDoNotConsumeTheBatch(): void
    {
        foreach (['x', 'y', 'z'] as $suffix) {
            $this->writeLegacy('%PDF-1.4 sirotek ' . $suffix);
        }
        $key = $this->writeLegacy('%PDF-1.4 evidovany');
        $this->insertDocument($key, $this->employeeId);

        $report = $this->service->run($this->supplierId, false, false, false, 1);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_ENCRYPTED], json_encode($report));
        self::assertSame(3, $report['counts'][Reencryption::STATUS_ORPHAN_SKIPPED] + $report['remaining']);
        self::assertFileDoesNotExist($this->legacyPath($key));
    }

    /** Podadresáře subjektů, dočasné a cizí soubory nejsou legacy plaintext. */
    public function testScanIgnoresEncryptedLayoutTemporaryAndForeignFiles(): void
    {
        $this->storage->store($this->supplierId, '%PDF-1.4 sifrovany', null, $this->employeeId);
        $base = PayrollDocumentStorage::baseDir($this->supplierId);
        mkdir($base . '/ab', 0750, true);
        file_put_contents($base . '/ab/.tmp-0123', 'x');
        file_put_contents($base . '/ab/readme.txt', 'x');
        file_put_contents($base . '/ab/' . str_repeat('c', 64), 'x');

        self::assertSame(0, $this->service->countLegacy($this->supplierId)['total']);
    }

    /**
     * Windows vrací z realpath() casing podle disku, ne podle zadání. Datový
     * adresář zadaný jiným casingem nesmí shodit guard proti path traversal.
     */
    public function testDataDirWithDifferentCasingOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Casing cest je rozdíl jen na Windows.');
        }
        putenv('MYINVOICE_DATA_DIR=' . strtoupper($this->dataDir));
        $key = $this->writeLegacy('%PDF-1.4 casing');
        $this->insertDocument($key, $this->employeeId);

        $report = $this->service->run($this->supplierId, false);

        self::assertSame(1, $report['counts'][Reencryption::STATUS_ENCRYPTED], json_encode($report));
        self::assertSame(0, $this->service->countLegacy($this->supplierId)['total']);
    }

    private function writeLegacy(string $bytes): string
    {
        $key = hash('sha256', $bytes);
        $path = $this->legacyPath($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $bytes);

        return $key;
    }

    private function legacyPath(string $key): string
    {
        return PayrollDocumentStorage::baseDir($this->supplierId)
            . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private function subjectPath(string $key, int $subjectId): string
    {
        return PayrollDocumentStorage::baseDir($this->supplierId)
            . '/subj-' . $subjectId . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private function keyExists(int $subjectId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_document_data_keys WHERE supplier_id = ? AND subject_id = ?',
        );
        $stmt->execute([$this->supplierId, $subjectId]);

        return $stmt->fetchColumn() !== false;
    }

    private function seedAnchors(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický zaměstnanec", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, "2019-06-01", "2019-06-15", "approved", 1)'
        )->execute([$this->supplierId]);
        $this->runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $this->runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            self::RESULT_HASH,
            str_repeat('c', 64),
        ]);
        $this->revisionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2019, "payroll_sheet", 1, "", ?, "{}", ?, "2019-12-31 12:00:00")'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::ANNUAL_HASH,
            str_repeat('e', 64),
        ]);
        $this->annualRevisionId = (int) $pdo->lastInsertId();
    }

    /** Dokument osoby se kotví k roční revizi, firemní k revizi běhu. */
    private function insertDocument(string $storageKey, ?int $employeeId): void
    {
        $ordinal = ++$this->sequence;
        $company = $employeeId === null;
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, annual_revision_id, employee_id,
                 document_kind, document_revision_no, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version,
                 file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "t1", "r1", ?, 1024,
                     "application/pdf", ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $company ? $this->runId : null,
            $company ? $this->revisionId : null,
            $company ? null : $this->annualRevisionId,
            $employeeId,
            $company ? 'monthly_bundle' : 'payroll_sheet',
            $ordinal,
            $company ? self::RESULT_HASH : self::ANNUAL_HASH,
            str_repeat('2', 64),
            $storageKey,
            $storageKey,
            'doklad-' . $ordinal . '.pdf',
            hash('sha256', 'idem-' . $ordinal),
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

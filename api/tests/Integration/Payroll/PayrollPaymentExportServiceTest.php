<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPaymentDownloadGrantRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentExportRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payment\SepaPaymentOrderWriter;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentDownloadGrantService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportStorage;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Pdf\PaymentOrderPdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentExportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SecretEncryption $encryption;
    private PayrollPaymentExportRepository $exports;
    private PayrollPaymentExportStorage $storage;
    private PayrollPaymentExportService $service;
    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $userId;
    private string $runtimeDir;

    protected function setUp(): void
    {
        $this->runtimeDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'myucto-payroll-export-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->runtimeDir, 0750, true));
        putenv('MYINVOICE_DATA_DIR=' . $this->runtimeDir);

        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->encryption = $encryption;
        $pdo = $connection->pdo();
        $supplierStatement = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        $userStatement = $pdo->query('SELECT MIN(id) FROM users');
        self::assertInstanceOf(\PDOStatement::class, $supplierStatement);
        self::assertInstanceOf(\PDOStatement::class, $userStatement);
        $sourceSupplierId = (int) $supplierStatement->fetchColumn();
        $this->userId = (int) $userStatement->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->exports = new PayrollPaymentExportRepository($connection);
        $this->storage = new PayrollPaymentExportStorage($encryption);
        $this->service = new PayrollPaymentExportService(
            $this->exports,
            $encryption,
            new AboPaymentOrderWriter(),
            new SepaPaymentOrderWriter(new IbanValidator()),
            $this->storage,
            new PaymentOrderPdfRenderer(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanupDatabaseFixtures();
            $this->db->close();
        }
        putenv('MYINVOICE_DATA_DIR');
        if (isset($this->runtimeDir) && is_dir($this->runtimeDir)) {
            $this->removeTree($this->runtimeDir);
        }
    }

    private function cleanupDatabaseFixtures(): void
    {
        $fixtureSupplierIds = array_values(array_filter(
            [$this->supplierId, $this->otherSupplierId],
            static fn (int $supplierId): bool => $supplierId > 0,
        ));
        if ($fixtureSupplierIds === []) {
            return;
        }

        $pdo = $this->db->pdo();
        $databaseStatement = $pdo->query('SELECT DATABASE()');
        self::assertInstanceOf(\PDOStatement::class, $databaseStatement);
        $database = $databaseStatement->fetchColumn();
        self::assertIsString($database);
        self::assertStringEndsWith('_test', $database);

        $placeholders = implode(
            ', ',
            array_fill(0, count($fixtureSupplierIds), '?'),
        );
        $paymentTables = [
            'payroll_payment_matches',
            'payroll_payment_allocations',
            'payroll_payment_export_download_grants',
            'payroll_payment_export_idempotency_keys',
            'payroll_payment_exports',
            'payroll_payment_items',
            'payroll_payment_batches',
            'payroll_payment_liabilities',
        ];
        foreach ($paymentTables as $table) {
            $totalStatement = $pdo->query(
                "SELECT COUNT(*) FROM {$table}",
            );
            self::assertInstanceOf(\PDOStatement::class, $totalStatement);
            $total = (int) $totalStatement->fetchColumn();
            $ownedStatement = $pdo->prepare(
                "SELECT COUNT(*) FROM {$table}"
                . " WHERE supplier_id IN ({$placeholders})",
            );
            $ownedStatement->execute($fixtureSupplierIds);
            self::assertSame(
                $total,
                (int) $ownedStatement->fetchColumn(),
                "Test nesmí vyčistit cizí data z {$table}.",
            );
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($paymentTables as $table) {
                $pdo->exec("TRUNCATE TABLE {$table}");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach (['activity_log', 'api_request_log'] as $table) {
            $statement = $pdo->prepare(
                "DELETE FROM {$table}"
                . " WHERE supplier_id IN ({$placeholders})",
            );
            $statement->execute($fixtureSupplierIds);
        }
        $deleteSuppliers = $pdo->prepare(
            "DELETE FROM supplier WHERE id IN ({$placeholders})",
        );
        $deleteSuppliers->execute($fixtureSupplierIds);
        self::assertSame(
            count($fixtureSupplierIds),
            $deleteSuppliers->rowCount(),
        );
    }

    public function testExportsAboAndReplaysExactArchivedBytes(): void
    {
        $batchId = $this->insertBatch('abo');

        $first = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-abo-export',
            $this->userId,
        );
        $replayed = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-abo-export',
            $this->userId,
        );

        self::assertTrue($first['created']);
        self::assertFalse($first['replayed']);
        self::assertFalse($replayed['created']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(
            array_diff_key($first, ['created' => true, 'replayed' => true]),
            array_diff_key(
                $replayed,
                ['created' => true, 'replayed' => true],
            ),
        );
        self::assertSame('abo', $first['export_format']);
        self::assertSame('text/plain; charset=us-ascii', $first['mime_type']);
        self::assertSame(1, $first['export_revision_no']);
        self::assertMatchesRegularExpression(
            '/^mzdy-platby-2099-01-10-[0-9]+\.kpc$/D',
            $first['suggested_filename'],
        );
        self::assertArrayNotHasKey('account_number', $first);
        self::assertArrayNotHasKey('bytes', $first);

        $bytes = $this->storage->readVerified(
            $this->supplierId,
            $first['storage_key'],
        );
        self::assertStringStartsWith('UHL1', $bytes);
        self::assertStringContainsString('1000000005', $bytes);
        self::assertSame(hash('sha256', $bytes), $first['file_sha256']);
        self::assertSame(strlen($bytes), $first['size_bytes']);
        self::assertSame(
            1,
            $this->countRows(
                'payroll_payment_exports',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );

        $stored = $this->exportRow($first['export_id']);
        self::assertStringNotContainsString(
            '1000000005',
            $this->stringValue($stored, 'manifest_json'),
        );
        self::assertSame(
            $first['file_sha256'],
            $this->stringValue($stored, 'storage_key'),
        );
        $storedFiles = $this->storedFiles($this->supplierId);
        self::assertCount(1, $storedFiles);
        $encryptedAtRest = file_get_contents($storedFiles[0]);
        self::assertIsString($encryptedAtRest);
        self::assertStringStartsWith('enc:v2:', $encryptedAtRest);
        self::assertStringNotContainsString('UHL1', $encryptedAtRest);
        self::assertStringNotContainsString(
            '1000000005',
            $encryptedAtRest,
        );
    }

    public function testExportsSepaWithExactCountAndTotal(): void
    {
        $batchId = $this->insertBatch('sepa');

        $result = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-sepa-export',
            $this->userId,
        );
        $secondRevision = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-sepa-export-second-revision',
            $this->userId,
        );
        $secondKeyReplay = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-sepa-export-second-revision',
            $this->userId,
        );
        self::assertTrue($result['created']);
        self::assertFalse($result['replayed']);
        self::assertFalse($secondRevision['created']);
        self::assertTrue($secondRevision['replayed']);
        self::assertSame(
            $result['export_id'],
            $secondRevision['export_id'],
            'Retry s novým klíčem nesmí založit duplicitní revizi.',
        );
        self::assertSame(
            $secondRevision['export_id'],
            $secondKeyReplay['export_id'],
            'Nový klíč musí po content replayi zůstat trvale svázaný.',
        );
        $bytes = $this->storage->readVerified(
            $this->supplierId,
            $result['storage_key'],
        );
        $secondBytes = $this->storage->readVerified(
            $this->supplierId,
            $secondRevision['storage_key'],
        );
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($bytes));
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace(
            'p',
            'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03',
        );

        self::assertSame(
            '1',
            $xpath->evaluate('string(//p:GrpHdr/p:NbOfTxs)'),
        );
        self::assertSame(
            '1234.56',
            $xpath->evaluate('string(//p:GrpHdr/p:CtrlSum)'),
        );
        self::assertSame(
            '2026-08-04T08:11:12',
            $xpath->evaluate('string(//p:GrpHdr/p:CreDtTm)'),
        );
        self::assertSame(
            'CZ1801000000001000000005',
            $xpath->evaluate('string(//p:CdtrAcct/p:Id/p:IBAN)'),
        );
        $itemReferenceStatement = $this->db->pdo()->prepare(
            'SELECT item_reference
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $itemReferenceStatement->execute([
            $this->supplierId,
            $batchId,
        ]);
        $itemReference = $itemReferenceStatement->fetchColumn();
        self::assertIsString($itemReference);
        self::assertSame(
            'MYUCTO-' . substr(hash('sha256', $itemReference), 0, 28),
            $xpath->evaluate(
                'string(//p:CdtTrfTxInf/p:PmtId/p:EndToEndId)',
            ),
        );
        self::assertSame(
            'Synteticka mzdova firma',
            $xpath->evaluate('string(//p:Dbtr/p:Nm)'),
        );
        self::assertSame(
            'Synteticka platebni osoba',
            $xpath->evaluate('string(//p:Cdtr/p:Nm)'),
        );
        self::assertSame($bytes, $secondBytes);
        self::assertSame('application/xml', $result['mime_type']);
        self::assertStringEndsWith('.xml', $result['suggested_filename']);
        $manifest = $this->stringValue(
            $this->exportRow($result['export_id']),
            'manifest_json',
        );
        self::assertStringNotContainsString(
            'Syntetická mzdová firma',
            $manifest,
        );
        self::assertStringNotContainsString(
            'Syntetická platební osoba',
            $manifest,
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_payment_exports',
                'supplier_id = ? AND batch_id = ?',
                [$this->supplierId, $batchId],
            ),
        );
        self::assertSame(
            2,
            $this->countRows(
                'payroll_payment_export_idempotency_keys',
                'supplier_id = ? AND batch_id = ?',
                [$this->supplierId, $batchId],
            ),
        );
    }

    public function testAboExportPreservesInstitutionPaymentSymbols(): void
    {
        $batchId = $this->insertBatch(
            'abo',
            institutionSymbols: true,
        );
        $result = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-institution-symbols',
            $this->userId,
        );
        $bytes = $this->storage->readVerified(
            $this->supplierId,
            $result['storage_key'],
        );

        self::assertStringContainsString('1234567890', $bytes);
        self::assertStringContainsString('2468', $bytes);
        self::assertStringContainsString('0558', $bytes);
        self::assertStringContainsString(
            'Zdravotni pojisteni 111',
            $bytes,
        );
    }

    public function testPdfDocumentLivesNextToBankFileOfSameBatch(): void
    {
        $batchId = $this->insertBatch('abo', institutionSymbols: true);

        $bankFile = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-abo-with-document',
            $this->userId,
        );
        $document = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-pdf-document',
            $this->userId,
            null,
            'pdf',
        );

        self::assertSame('abo', $bankFile['export_format']);
        self::assertTrue($document['created']);
        self::assertSame('pdf', $document['export_format']);
        self::assertSame('application/pdf', $document['mime_type']);
        self::assertSame(1, $document['export_revision_no']);
        self::assertMatchesRegularExpression(
            '/^mzdy-platby-2099-01-10-[0-9]+-prikaz\.pdf$/D',
            $document['suggested_filename'],
        );
        self::assertNotSame(
            $bankFile['export_id'],
            $document['export_id'],
            'Doklad nesmí přepsat soubor pro banku.',
        );
        self::assertSame(
            2,
            $this->countRows(
                'payroll_payment_exports',
                'supplier_id = ? AND batch_id = ?',
                [$this->supplierId, $batchId],
            ),
        );

        $bytes = $this->storage->readVerified(
            $this->supplierId,
            $document['storage_key'],
        );
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertSame(hash('sha256', $bytes), $document['file_sha256']);
        self::assertSame(strlen($bytes), $document['size_bytes']);
        $manifest = $this->stringValue(
            $this->exportRow($document['export_id']),
            'manifest_json',
        );
        self::assertStringContainsString('"export_format":"pdf"', $manifest);
        self::assertStringNotContainsString(
            'Syntetická platební osoba',
            $manifest,
        );
    }

    public function testPdfDocumentReplaysArchivedRevisionForSameSnapshot(): void
    {
        $batchId = $this->insertBatch('sepa');

        $first = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-pdf-first',
            $this->userId,
            null,
            'pdf',
        );
        $sameKey = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-pdf-first',
            $this->userId,
            null,
            'pdf',
        );
        $newKey = $this->service->export(
            $this->supplierId,
            $batchId,
            'synthetic-pdf-second-key',
            $this->userId,
            null,
            'pdf',
        );

        self::assertTrue($first['created']);
        self::assertTrue($sameKey['replayed']);
        self::assertTrue($newKey['replayed']);
        self::assertSame($first['export_id'], $sameKey['export_id']);
        self::assertSame(
            $first['export_id'],
            $newKey['export_id'],
            'Týž snapshot dávky nesmí založit druhou revizi dokladu.',
        );
        self::assertSame($first['file_sha256'], $newKey['file_sha256']);
        self::assertSame(
            1,
            $this->countRows(
                'payroll_payment_exports',
                'supplier_id = ? AND batch_id = ?',
                [$this->supplierId, $batchId],
            ),
        );
        self::assertSame(
            2,
            $this->countRows(
                'payroll_payment_export_idempotency_keys',
                'supplier_id = ? AND batch_id = ?',
                [$this->supplierId, $batchId],
            ),
        );
    }

    public function testRejectsUnknownFormatAndCrossFormatIdempotencyKey(): void
    {
        $batchId = $this->insertBatch('abo');

        try {
            $this->service->export(
                $this->supplierId,
                $batchId,
                'unsupported-format-export',
                $this->userId,
                null,
                'csv',
            );
            self::fail('Nepodporovaný formát musí export zastavit.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->service->export(
                $this->supplierId,
                $batchId,
                'sepa-from-abo-batch',
                $this->userId,
                null,
                'sepa',
            );
            self::fail('Soubor pro banku musí odpovídat formátu dávky.');
        } catch (\DomainException) {
        }

        $this->service->export(
            $this->supplierId,
            $batchId,
            'shared-idempotency-key',
            $this->userId,
            null,
            'abo',
        );
        $this->expectException(\DomainException::class);
        $this->service->export(
            $this->supplierId,
            $batchId,
            'shared-idempotency-key',
            $this->userId,
            null,
            'pdf',
        );
    }

    public function testRejectsTamperedSnapshotAndCrossTenantBatch(): void
    {
        $tamperedBatchId = $this->insertBatch('abo', true);

        try {
            $this->service->export(
                $this->supplierId,
                $tamperedBatchId,
                'tampered-export',
                $this->userId,
            );
            self::fail('Pozměněný hash snapshotu musí export zastavit.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'snapshot',
                mb_strtolower($exception->getMessage(), 'UTF-8'),
            );
        }

        $otherBatchId = $this->insertBatch(
            'abo',
            false,
            $this->otherSupplierId,
        );
        $this->expectException(\DomainException::class);
        $this->service->export(
            $this->supplierId,
            $otherBatchId,
            'cross-tenant-export',
            $this->userId,
        );
    }

    public function testRemovesNewStorageObjectAfterDatabaseRollback(): void
    {
        $batchId = $this->insertBatch('abo');

        try {
            $this->service->export(
                $this->supplierId,
                $batchId,
                'rollback-export',
                PHP_INT_MAX,
            );
            self::fail('Neplatný autor musí vložení exportu zastavit.');
        } catch (\Throwable) {
            self::assertSame(
                0,
                $this->countRows(
                    'payroll_payment_exports',
                    'supplier_id = ?',
                    [$this->supplierId],
                ),
            );
            self::assertSame(
                [],
                $this->storedFiles($this->supplierId),
                'Nově vytvořený orphan musí být po rollbacku odstraněn.',
            );
        }
    }

    public function testAuditFailureRollsBackExportAndRemovesStorageObject(): void
    {
        $batchId = $this->insertBatch('abo');

        try {
            $this->service->export(
                $this->supplierId,
                $batchId,
                'audit-rollback-export',
                $this->userId,
                static function (array $export): void {
                    self::assertGreaterThan(0, $export['export_id']);
                    throw new \RuntimeException(
                        'synthetic export audit failure',
                    );
                },
            );
            self::fail('Selhání auditu musí vrátit export i soubor.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'synthetic export audit failure',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_exports',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame([], $this->storedFiles($this->supplierId));
    }

    public function testExportAndConsumeRejectForeignOuterTransaction(): void
    {
        $batchId = $this->insertBatch('abo');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->service->export(
                $this->supplierId,
                $batchId,
                'outer-transaction-export',
                $this->userId,
            );
            self::fail('Export uvnitř cizí transakce musí být odmítnut.');
        } catch (\LogicException) {
        } finally {
            $pdo->rollBack();
        }

        $export = $this->service->export(
            $this->supplierId,
            $batchId,
            'outer-transaction-grant-source',
            $this->userId,
        );
        $grants = new PayrollPaymentDownloadGrantService(
            new PayrollPaymentDownloadGrantRepository($this->db),
            $this->exports,
            $this->storage,
        );
        $grant = $grants->issue(
            $this->supplierId,
            $export['export_id'],
            $this->userId,
            60,
        );
        $pdo->beginTransaction();
        try {
            $grants->consume(
                $this->supplierId,
                $this->userId,
                $grant['token'],
            );
            self::fail('Stažení uvnitř cizí transakce musí být odmítnuto.');
        } catch (\LogicException) {
        } finally {
            $pdo->rollBack();
        }
    }

    public function testStorageRejectsSupplierDirectoryEscapeWhenSupported(): void
    {
        $base = PayrollPaymentExportStorage::baseDir($this->supplierId);
        $root = dirname($base);
        self::assertTrue(is_dir($root) || mkdir($root, 0750, true));
        $outside = $this->runtimeDir . DIRECTORY_SEPARATOR
            . 'outside-payroll-storage';
        self::assertTrue(mkdir($outside, 0750, true));
        if (!@symlink($outside, $base)) {
            $this->markTestSkipped(
                'Platforma nepovoluje vytvoření adresářového symlinku.',
            );
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->storage->store(
                $this->supplierId,
                'synthetic path escape payload',
            );
        } finally {
            if (is_link($base)) {
                unlink($base);
            }
        }
    }

    public function testStorageRejectsReadThroughSupplierDirectoryEscape(): void
    {
        $bytes = 'synthetic archived payment bytes';
        $hash = hash('sha256', $bytes);
        $base = PayrollPaymentExportStorage::baseDir($this->supplierId);
        $root = dirname($base);
        self::assertTrue(is_dir($root) || mkdir($root, 0750, true));
        $outside = $this->runtimeDir . DIRECTORY_SEPARATOR
            . 'outside-payroll-read';
        $outsidePrefix = $outside . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2);
        self::assertTrue(mkdir($outsidePrefix, 0750, true));
        $ciphertext = $this->encryption->encryptFor(
            $bytes,
            "payroll-payment-export-storage:{$this->supplierId}:{$hash}",
        );
        self::assertSame(
            strlen($ciphertext),
            file_put_contents(
                $outsidePrefix . DIRECTORY_SEPARATOR . $hash,
                $ciphertext,
            ),
        );
        if (!@symlink($outside, $base)) {
            $this->markTestSkipped(
                'Platforma nepovoluje vytvoření adresářového symlinku.',
            );
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->storage->readVerified($this->supplierId, $hash);
        } finally {
            if (is_link($base)) {
                unlink($base);
            }
        }
    }

    public function testStorageRejectsContextFreeV1Ciphertext(): void
    {
        $bytes = 'synthetic context-bound archive';
        $stored = $this->storage->store($this->supplierId, $bytes);
        $files = $this->storedFiles($this->supplierId);
        self::assertCount(1, $files);
        $legacy = $this->encryption->encrypt($bytes);
        self::assertStringStartsWith('enc:v1:', $legacy);
        self::assertSame(
            strlen($legacy),
            file_put_contents($files[0], $legacy),
        );

        $this->expectException(\RuntimeException::class);
        $this->storage->readVerified(
            $this->supplierId,
            $stored['storage_key'],
        );
    }

    public function testOneUseGrantIsTenantAndUserBoundAndExpires(): void
    {
        $batchId = $this->insertBatch('abo');
        $export = $this->service->export(
            $this->supplierId,
            $batchId,
            'grant-export',
            $this->userId,
        );
        $grants = new PayrollPaymentDownloadGrantService(
            new PayrollPaymentDownloadGrantRepository($this->db),
            $this->exports,
            $this->storage,
        );

        $grant = $grants->issue(
            $this->supplierId,
            $export['export_id'],
            $this->userId,
            60,
        );
        self::assertArrayHasKey('token', $grant);
        self::assertStringNotContainsString(
            $grant['token'],
            $this->grantTokenHash($grant['grant_id']),
        );

        foreach ([
            [$this->otherSupplierId, $this->userId],
            [$this->supplierId, PHP_INT_MAX],
        ] as [$supplierId, $userId]) {
            try {
                $grants->consume($supplierId, $userId, $grant['token']);
                self::fail('Grant nesmí fungovat pro jinou firmu ani osobu.');
            } catch (\DomainException) {
            }
        }

        $download = $grants->consume(
            $this->supplierId,
            $this->userId,
            $grant['token'],
        );
        self::assertSame('private, no-store', $download['cache_control']);
        self::assertSame($export['mime_type'], $download['mime_type']);
        self::assertSame(
            $export['suggested_filename'],
            $download['suggested_filename'],
        );
        self::assertSame(
            $export['file_sha256'],
            hash('sha256', $download['bytes']),
        );

        $this->expectException(\DomainException::class);
        $grants->consume(
            $this->supplierId,
            $this->userId,
            $grant['token'],
        );
    }

    public function testExpiredGrantCannotBeConsumed(): void
    {
        $batchId = $this->insertBatch('abo');
        $export = $this->service->export(
            $this->supplierId,
            $batchId,
            'expired-grant-export',
            $this->userId,
        );
        $grants = new PayrollPaymentDownloadGrantService(
            new PayrollPaymentDownloadGrantRepository($this->db),
            $this->exports,
            $this->storage,
        );
        $token = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
        $nowStatement = $this->db->pdo()->query(
            'SELECT UTC_TIMESTAMP()',
        );
        self::assertInstanceOf(\PDOStatement::class, $nowStatement);
        $now = new \DateTimeImmutable(
            (string) $nowStatement->fetchColumn(),
            new \DateTimeZone('UTC'),
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_export_download_grants
                (supplier_id, export_id, user_id, token_hash,
                 created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $export['export_id'],
            $this->userId,
            hash('sha256', $token, true),
            $now->modify('-31 seconds')->format('Y-m-d H:i:s'),
            $now->modify('-1 second')->format('Y-m-d H:i:s'),
        ]);

        $this->expectException(\DomainException::class);
        $grants->consume(
            $this->supplierId,
            $this->userId,
            $token,
        );
    }

    private function insertBatch(
        string $format,
        bool $tampered = false,
        ?int $supplierId = null,
        bool $institutionSymbols = false,
    ): int {
        $supplierId ??= $this->supplierId;
        $isSepa = $format === 'sepa';
        $batchReference = "synthetic-{$format}-"
            . bin2hex(random_bytes(6));
        $itemReference = "synthetic-item-{$format}-"
            . bin2hex(random_bytes(6));
        $recipientReference = 'employee-account:123';
        $currencyCode = $isSepa ? 'EUR' : 'CZK';
        $payer = $isSepa
            ? [
                'account_holder_name' => 'Syntetická mzdová firma',
                'iban' => 'CZ1801000000001000000005',
                'bic' => 'KOMBCZPP',
            ]
            : [
                'account_holder_name' => 'Syntetická mzdová firma',
                'account_number' => '1000000005',
                'bank_code' => '0100',
            ];
        $instruction = [
            'schema_reference' =>
                'payroll-payment-recipient-instruction.v1',
            'recipient_reference' => $recipientReference,
            'amount_minor' => 123_456,
            'currency_code' => $currencyCode,
            'planned_payment_date' => '2099-01-10',
            'liabilities' => [[
                'id' => 1,
                'reference' => 'synthetic-liability',
                'amount_minor' => 123_456,
                'source_snapshot_hash' => hash(
                    'sha256',
                    'synthetic-liability',
                ),
            ]],
            'recipient_name' => 'Syntetická platební osoba',
            ...($institutionSymbols
                ? [
                    'variable_symbol' => '1234567890',
                    'specific_symbol' => '2468',
                    'constant_symbol' => '0558',
                    'payment_message' =>
                        'Zdravotni pojisteni 111',
                ]
                : []),
            ...($isSepa
                ? ['iban' => 'CZ1801000000001000000005']
                : [
                    'account_number' => '1000000005',
                    'bank_code' => '0100',
                ]),
        ];
        $instructionJson = CanonicalJson::encode($instruction);
        $instructionHash = hash('sha256', $instructionJson);
        $snapshot = [
            'schema_reference' => 'payroll-payment-batch-snapshot.v1',
            'batch_reference' => $batchReference,
            'channel' => 'bank',
            'export_format' => $format,
            'direction' => 'outgoing',
            'planned_payment_date' => '2099-01-10',
            'creation_datetime' => '2026-08-04T08:11:12+00:00',
            'currency_code' => $currencyCode,
            'payer_reference' => 'currency:1',
            'payer_instruction' => $payer,
            'declared_total_minor' => 123_456,
            'declared_item_count' => 1,
            'items' => [[
                'item_reference' => $itemReference,
                'recipient_reference' => $recipientReference,
                'amount_minor' => 123_456,
                'instruction_hash' => $instructionHash,
                'liabilities' => $instruction['liabilities'],
            ]],
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $tampered
            ? hash('sha256', 'tampered-snapshot')
            : hash('sha256', $snapshotJson);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, "bank", ?, "outgoing", "2099-01-10", ?,
                     "currency:1", 123456, 1, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchReference,
            $format,
            $currencyCode,
            $this->encryption->encryptFor(
                $snapshotJson,
                "payroll-payment-batch:{$supplierId}:{$batchReference}",
            ),
            $snapshotHash,
            hash('sha256', $batchReference, true),
            $this->userId,
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference,
                 recipient_reference, amount_minor,
                 instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, 123456, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            $itemReference,
            $recipientReference,
            $this->encryption->encryptFor(
                $instructionJson,
                "payroll-payment-item:{$supplierId}:{$itemReference}",
            ),
            $instructionHash,
            hash('sha256', $itemReference, true),
        ]);

        return $batchId;
    }

    /** @return array<string,mixed> */
    private function exportRow(int $exportId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_exports
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $exportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $result = [];
        foreach ($row as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    private function grantTokenHash(int $grantId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT HEX(token_hash)
               FROM payroll_payment_export_download_grants
              WHERE id = ?',
        );
        $statement->execute([$grantId]);

        return (string) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function storedFiles(int $supplierId): array
    {
        $base = PayrollPaymentExportStorage::baseDir($supplierId);
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** @param list<int|string> $params */
    private function countRows(
        string $table,
        string $where,
        array $params,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $row */
    private function stringValue(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        self::assertIsString($value);

        return $value;
    }

    private function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}

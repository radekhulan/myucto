<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollPeriodExportRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportArchiveBuilder;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportService;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportStorage;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ZipArchive;

#[Group('integration')]
final class PayrollPeriodExportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeriodExportService $service;
    private PayrollPeriodExportStorage $storage;
    private PayrollDocumentStorage $documentStorage;
    private PayrollDocumentRepository $documents;
    private SecretEncryption $encryption;
    private PayrollSensitiveData $sensitiveData;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private string $dataDir;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payroll-period-export-'
            . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->dataDir, 0750, true));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $container = Bootstrap::buildContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        if (!$connection->hasTable('payroll_period_exports')) {
            self::fail('Migrace 1551 neproběhla.');
        }
        $secretEncryption = $container->get(SecretEncryption::class);
        $submissionService = $container->get(PayrollSubmissionService::class);
        $documentStorage = $container->get(PayrollDocumentStorage::class);
        $documentRepository = $container->get(PayrollDocumentRepository::class);
        $sensitiveData = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(SecretEncryption::class, $secretEncryption);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissionService);
        self::assertInstanceOf(PayrollDocumentStorage::class, $documentStorage);
        self::assertInstanceOf(PayrollDocumentRepository::class, $documentRepository);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitiveData);
        $this->encryption = $secretEncryption;
        $this->sensitiveData = $sensitiveData;
        $this->documentStorage = $documentStorage;
        $this->documents = $documentRepository;
        $this->storage = new PayrollPeriodExportStorage($secretEncryption);
        $this->service = new PayrollPeriodExportService(
            new PayrollPeriodExportRepository($connection),
            new PayrollPeriodExportArchiveBuilder(),
            $this->storage,
            $documentStorage,
            $submissionService,
            $secretEncryption,
            $sensitiveData,
        );

        $sourceSupplierId = $this->integer(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $this->userId = $this->integer(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
        if (isset($this->dataDir)) {
            $this->removeDirectory($this->dataDir);
        }
        if (isset($this->previousDataDir)) {
            $this->previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        }
    }

    public function testMonthlyAndAnnualExportsAreTenantBoundDeterministicAndDownloadOnce(): void
    {
        [$runId, $revisionId, $employeeIds, $revisionHash] =
            $this->approvedRevision(500);
        $pdf = '%PDF-1.4 synthetic archived payroll document';
        $storedDocument = $this->documentStorage->store(
            $this->supplierId,
            $pdf,
        );
        $archivedDocument = $this->documents->insertOrGet([
            'supplier_id' => $this->supplierId,
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'employee_id' => $employeeIds[0],
            'document_kind' => 'payslip',
            'document_revision_no' => 1,
            'supersedes_document_id' => null,
            'source_snapshot_hash' => str_repeat('d', 64),
            'revision_snapshot_hash' => $revisionHash,
            'template_version' => 'synthetic-v1',
            'renderer_version' => 'synthetic-v1',
            'file_sha256' => $storedDocument['file_sha256'],
            'size_bytes' => $storedDocument['size_bytes'],
            'mime_type' => 'application/pdf',
            'storage_key' => $storedDocument['storage_key'],
            'suggested_filename' => 'synthetic-payslip.pdf',
            'manifest_json' => null,
            'idempotency_key_hash' => hash(
                'sha256',
                'period-export-document',
            ),
            'created_by' => null,
        ]);
        $protocol = '<JMHZProtocol synthetic="true"/>';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_imported_jmhz_protocols
                (supplier_id, environment, protocol_kind, variable_symbol,
                 period_month, period_year, status_code, status_name,
                 payload_sha256, payload_xml, dedupe_key)
             VALUES (?, "test", "processing", "1234567890", 8, 2097,
                     1, "Received", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            hash('sha256', $protocol),
            $protocol,
            hash('sha256', 'synthetic-period-export-protocol'),
        ]);
        $protocolId = (int) $this->db->pdo()->lastInsertId();

        $invalidUserId = $this->integer(
            'SELECT COALESCE(MAX(id), 0) + 1000000 FROM users',
        );
        try {
            $this->service->createMonthly(
                $this->supplierId,
                '2097-08',
                $invalidUserId,
            );
            self::fail('Neexistující autor musí zablokovat zápis exportu.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        self::assertCount(
            1,
            $this->storedExportFiles($this->supplierId),
            'DB chyba nesmí smazat blob, který může převzít souběžný export.',
        );

        $monthly = $this->service->createMonthly(
            $this->supplierId,
            '2097-08',
            $this->userId,
        );
        $replayed = $this->service->createMonthly(
            $this->supplierId,
            '2097-08',
            $this->userId,
        );
        self::assertSame($monthly['id'], $replayed['id']);
        self::assertSame($monthly['file_sha256'], $replayed['file_sha256']);
        self::assertSame('monthly', $monthly['export_scope']);
        self::assertMatchesRegularExpression(
            '/^mzdy-2097-08-[a-f0-9]{12}\.zip$/D',
            (string) $monthly['suggested_filename'],
        );

        $encryptedPath = PayrollPeriodExportStorage::baseDir(
            $this->supplierId,
        ) . '/' . substr((string) $monthly['storage_key'], 0, 2)
            . '/' . $monthly['storage_key'];
        $ciphertext = file_get_contents($encryptedPath);
        self::assertIsString($ciphertext);
        self::assertStringStartsWith('enc:v2:', $ciphertext);
        self::assertStringNotContainsString($pdf, $ciphertext);
        self::assertStringNotContainsString($protocol, $ciphertext);

        $zipBytes = $this->storage->readVerified(
            $this->supplierId,
            (string) $monthly['storage_key'],
        );
        self::assertSame(
            (string) $monthly['file_sha256'],
            hash('sha256', $zipBytes),
        );
        $zipPath = $this->dataDir . '/inspect.zip';
        file_put_contents($zipPath, $zipBytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::RDONLY) === true);
        $dataBytes = $zip->getFromName('data/payroll.json');
        self::assertIsString($dataBytes);
        $data = json_decode($dataBytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertCount(500, $data['people']);
        self::assertSame(
            $pdf,
            $zip->getFromName(sprintf(
                'documents/document-%012d.pdf',
                (int) $archivedDocument['id'],
            )),
        );
        self::assertSame(
            $protocol,
            $zip->getFromName(sprintf(
                'protocols/test/jmhz-protocol-%012d.xml',
                $protocolId,
            )),
        );
        self::assertStringNotContainsString(
            'storage_key',
            $dataBytes,
        );
        self::assertStringNotContainsString(
            'content_ciphertext',
            $dataBytes,
        );
        self::assertStringNotContainsString('password', strtolower($dataBytes));
        $zip->close();

        $annualSnapshot = [
            'schema_version' => 'synthetic-annual.v1',
            'employee_sequence' => 1,
            'annual_net_minor' => 2500000,
        ];
        $annualSnapshotJson = CanonicalJson::encode($annualSnapshot);
        $annualManifestJson = CanonicalJson::encode([
            'source_revision_ids' => [$revisionId],
        ]);
        $annualManifestHash = hash('sha256', $annualManifestJson);
        $annualSnapshotHash = $this->sensitiveData->keyedFingerprint(
            $annualSnapshotJson,
            'annual-payroll-snapshot-v1',
            $this->supplierId,
        );
        $annualCiphertext = $this->encryption->encryptFor(
            $annualSnapshotJson,
            implode(':', [
                'payroll-annual-document',
                (string) $this->supplierId,
                (string) $employeeIds[0],
                '2097',
                'payroll_sheet',
                $annualManifestHash,
            ]),
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_by, approved_at)
             VALUES (?, ?, 2097, ?, 1, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $employeeIds[0],
            'payroll_sheet',
            $annualCiphertext,
            $annualSnapshotHash,
            $annualManifestJson,
            $annualManifestHash,
            $this->userId,
        ]);
        $annualRevisionId = (int) $this->db->pdo()->lastInsertId();
        $personHashStatement = $this->db->pdo()->prepare(
            'SELECT result_hash FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
        );
        $personHashStatement->execute([
            $this->supplierId,
            $revisionId,
            $employeeIds[0],
        ]);
        $personResultHash = $personHashStatement->fetchColumn();
        self::assertIsString($personResultHash);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_document_sources
                (supplier_id, annual_revision_id, run_revision_id,
                 employee_id, period_start, person_result_hash)
             VALUES (?, ?, ?, ?, "2097-08-01", ?)',
        )->execute([
            $this->supplierId,
            $annualRevisionId,
            $revisionId,
            $employeeIds[0],
            $personResultHash,
        ]);

        $annual = $this->service->createAnnual(
            $this->supplierId,
            2097,
            $this->userId,
        );
        self::assertSame('annual', $annual['export_scope']);
        self::assertMatchesRegularExpression(
            '/^mzdy-2097-[a-f0-9]{12}\.zip$/D',
            (string) $annual['suggested_filename'],
        );
        $annualBytes = $this->storage->readVerified(
            $this->supplierId,
            (string) $annual['storage_key'],
        );
        file_put_contents($zipPath, $annualBytes);
        self::assertTrue($zip->open($zipPath, ZipArchive::RDONLY) === true);
        $annualDataBytes = $zip->getFromName('data/payroll.json');
        self::assertIsString($annualDataBytes);
        $annualData = json_decode(
            $annualDataBytes,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertEquals(
            $annualSnapshot,
            $annualData['annual_revisions'][0]['snapshot_json'],
        );
        self::assertStringNotContainsString(
            'snapshot_ciphertext',
            $annualDataBytes,
        );
        $zip->close();

        try {
            $this->service->createMonthly(
                $this->otherSupplierId,
                '2097-08',
                $this->userId,
            );
            self::fail('Cizí firma nesmí použít zdroje prvního tenanta.');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        $grant = $this->service->issueDownloadGrant(
            $this->supplierId,
            (int) $monthly['id'],
            $this->userId,
            60,
        );
        foreach ([
            [$this->otherSupplierId, $this->userId],
            [$this->supplierId, $this->userId + 1_000_000],
        ] as [$supplierId, $userId]) {
            try {
                $this->service->consumeDownload(
                    $supplierId,
                    $userId,
                    $grant['token'],
                );
                self::fail('Grant nesmí fungovat pro jinou firmu nebo osobu.');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
        $download = $this->service->consumeDownload(
            $this->supplierId,
            $this->userId,
            $grant['token'],
        );
        self::assertSame($zipBytes, $download['bytes']);
        try {
            $this->service->consumeDownload(
                $this->supplierId,
                $this->userId,
                $grant['token'],
            );
            self::fail('Jednorázový grant nesmí jít použít podruhé.');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
    }

    /** @return array{int,int,list<int>,string} */
    private function approvedRevision(int $personCount): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date)
             VALUES (?, "2097-08-01", "2097-09-15")',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = json_encode(
            ['schema' => 'synthetic-input.v1', 'person_count' => $personCount],
            JSON_THROW_ON_ERROR,
        );
        $result = json_encode(
            ['schema' => 'synthetic-result.v1', 'person_count' => $personCount],
            JSON_THROW_ON_ERROR,
        );
        $resultHash = hash('sha256', $result);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-run.v1", ?, ?, ?, ?, ?,
                     UNHEX(?), NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            $resultHash,
            hash('sha256', 'period-export-revision'),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $employeeStatement = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        );
        $personStatement = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        );
        $employeeIds = [];
        for ($index = 1; $index <= $personCount; ++$index) {
            $employeeStatement->execute([
                $this->supplierId,
                sprintf('Syntetická osoba %04d', $index),
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $employeeIds[] = $employeeId;
            $personResult = json_encode(
                ['employee_sequence' => $index, 'net_minor' => 2500000],
                JSON_THROW_ON_ERROR,
            );
            $personStatement->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                $personResult,
                hash('sha256', $personResult),
            ]);
        }

        return [$runId, $revisionId, $employeeIds, $resultHash];
    }

    private function integer(string $sql): int
    {
        $statement = $this->db->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function storedExportFiles(int $supplierId): array
    {
        $base = PayrollPeriodExportStorage::baseDir($supplierId);
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS,
            ),
        ) as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}

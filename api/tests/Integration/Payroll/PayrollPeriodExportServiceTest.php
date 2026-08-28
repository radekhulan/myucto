<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollPeriodExportJobRepository;
use MyInvoice\Repository\Payroll\PayrollPeriodExportRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportArchiveBuilder;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportScope;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportService;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportQueueService;
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
    private PayrollPeriodExportQueueService $queue;
    private PayrollPeriodExportJobRepository $jobs;
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
        $this->jobs = new PayrollPeriodExportJobRepository($connection);
        $this->queue = new PayrollPeriodExportQueueService($this->jobs, $this->service);

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

    public function testPeriodExportQueueDefersRenderingAndCompletesUnderLease(): void
    {
        if (!$this->db->hasTable('payroll_period_export_jobs')) {
            self::fail('Migrace 1604 neproběhla.');
        }
        $this->approvedRevision(100);

        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);
        self::assertSame('queued', $queued['status']);
        self::assertNull($queued['export_id']);
        self::assertSame(
            0,
            $this->countRows('payroll_period_exports'),
            'HTTP enqueue nesmí blokovat renderováním ZIPu.',
        );

        $repeat = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);
        self::assertSame($queued['id'], $repeat['id']);
        self::assertSame(
            ['processed' => 1, 'succeeded' => 1, 'failed' => 0],
            $this->queue->processAvailable(),
        );

        $completed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertIsInt($completed['export_id']);
        $afterCompleted = $this->queue->enqueueMonthly(
            $this->supplierId,
            '2097-08',
            $this->userId,
        );
        self::assertNotSame($queued['id'], $afterCompleted['id']);
        self::assertSame('queued', $afterCompleted['status']);

        $grant = $this->service->issueDownloadGrant(
            $this->supplierId,
            $completed['export_id'],
            $this->userId,
            60,
        );
        self::assertSame($completed['export_id'], $grant['export_id']);
        self::assertNull($this->queue->detail($this->otherSupplierId, (int) $queued['id']));
    }

    public function testQueuedExportKeepsCompletedBinaryPartWhenFinalArchiveRunsLater(): void
    {
        if (!$this->db->hasTable('payroll_period_export_job_parts')) {
            self::fail('Migrace 1606 neproběhla.');
        }
        [$runId, $revisionId, $employeeIds, $revisionHash] = $this->approvedRevision(1);
        $bytes = '%PDF-1.4 synthetic resumable payroll export part';
        $stored = $this->documentStorage->store($this->supplierId, $bytes);
        $this->documents->insertOrGet([
            'supplier_id' => $this->supplierId,
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'employee_id' => $employeeIds[0],
            'document_kind' => 'payslip',
            'document_revision_no' => 1,
            'supersedes_document_id' => null,
            'source_snapshot_hash' => str_repeat('e', 64),
            'revision_snapshot_hash' => $revisionHash,
            'template_version' => 'synthetic-v1',
            'renderer_version' => 'synthetic-v1',
            'file_sha256' => $stored['file_sha256'],
            'size_bytes' => $stored['size_bytes'],
            'mime_type' => 'application/pdf',
            'storage_key' => $stored['storage_key'],
            'suggested_filename' => 'synthetic-resumable-payslip.pdf',
            'manifest_json' => null,
            'idempotency_key_hash' => hash('sha256', 'resumable-period-export-document'),
            'created_by' => null,
        ]);
        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);

        self::assertSame(['processed' => 1, 'succeeded' => 1, 'failed' => 0], $this->queue->processAvailable());
        $afterPart = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($afterPart);
        self::assertSame('queued', $afterPart['status']);
        self::assertNull($afterPart['export_id']);
        self::assertSame(1, $this->countRows('payroll_period_export_job_parts WHERE status = "completed"'));

        $this->documentStorage->delete($this->supplierId, $stored['storage_key']);
        self::assertSame(['processed' => 1, 'succeeded' => 1, 'failed' => 0], $this->queue->processAvailable());
        $completed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertIsInt($completed['export_id']);
    }

    public function testResumableExportCompletesMoreThanThreeSuccessfulParts(): void
    {
        $this->requirePartsMigration();
        [$runId, $revisionId, $employeeIds, $revisionHash] = $this->approvedRevision(4);
        foreach ($employeeIds as $index => $employeeId) {
            $this->archiveDocument(
                $runId,
                $revisionId,
                $employeeId,
                $revisionHash,
                '%PDF-1.4 synthetic resumable part ' . $index,
                'many-parts-' . $index,
            );
        }
        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);

        self::assertSame(
            ['processed' => 5, 'succeeded' => 5, 'failed' => 0],
            $this->queue->processAvailable(10),
        );
        $completed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertSame(5, $completed['attempt_count']);
        self::assertSame(0, $completed['failure_count']);
        self::assertSame(5, $this->countRows(
            'payroll_period_export_job_parts WHERE job_id = ' . (int) $queued['id'] . ' AND status = "completed"',
        ));
    }

    public function testPartBackoffDoesNotConsumeParentFailureBudget(): void
    {
        $this->requirePartsMigration();
        [$runId, $revisionId, $employeeIds, $revisionHash] = $this->approvedRevision(1);
        $bytes = '%PDF-1.4 synthetic retryable resumable part';
        $stored = $this->archiveDocument(
            $runId,
            $revisionId,
            $employeeIds[0],
            $revisionHash,
            $bytes,
            'retryable-part',
        );
        $this->documentStorage->delete($this->supplierId, $stored['storage_key']);
        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            self::assertSame(
                ['processed' => 1, 'succeeded' => 0, 'failed' => 1],
                $this->queue->processAvailable(),
            );
            $waiting = $this->queue->detail($this->supplierId, (int) $queued['id']);
            self::assertIsArray($waiting);
            self::assertSame('retry_wait', $waiting['status']);
            self::assertSame(0, $waiting['failure_count']);
            $this->makePartRetryAvailable((int) $queued['id']);
        }

        $restored = $this->documentStorage->store($this->supplierId, $bytes);
        self::assertSame($stored['storage_key'], $restored['storage_key']);
        self::assertSame(
            ['processed' => 2, 'succeeded' => 2, 'failed' => 0],
            $this->queue->processAvailable(2),
        );
        $completed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertSame(4, $completed['attempt_count']);
        self::assertSame(0, $completed['failure_count']);
    }

    public function testThirdFailureOfPartFailsParentJobTerminally(): void
    {
        $this->requirePartsMigration();
        [$runId, $revisionId, $employeeIds, $revisionHash] = $this->approvedRevision(1);
        $stored = $this->archiveDocument(
            $runId,
            $revisionId,
            $employeeIds[0],
            $revisionHash,
            '%PDF-1.4 synthetic terminal resumable part',
            'terminal-part',
        );
        $this->documentStorage->delete($this->supplierId, $stored['storage_key']);
        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);

        for ($attempt = 1; $attempt <= PayrollPeriodExportJobRepository::MAX_ATTEMPTS; ++$attempt) {
            self::assertSame(
                ['processed' => 1, 'succeeded' => 0, 'failed' => 1],
                $this->queue->processAvailable(),
            );
            if ($attempt < PayrollPeriodExportJobRepository::MAX_ATTEMPTS) {
                $this->makePartRetryAvailable((int) $queued['id']);
            }
        }

        $failed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($failed);
        self::assertSame('failed', $failed['status']);
        self::assertSame(0, $failed['failure_count']);
        self::assertSame(1, $this->countRows(
            'payroll_period_export_job_parts WHERE job_id = ' . (int) $queued['id'] . ' AND status = "failed"',
        ));
    }

    public function testThirdStalePartAttemptFailsParentBeforeClaimingNextPart(): void
    {
        $this->requirePartsMigration();
        [$runId, $revisionId, $employeeIds, $revisionHash] = $this->approvedRevision(2);
        foreach ($employeeIds as $index => $employeeId) {
            $this->archiveDocument(
                $runId,
                $revisionId,
                $employeeId,
                $revisionHash,
                '%PDF-1.4 synthetic stale part ' . $index,
                'stale-part-' . $index,
            );
        }
        $queued = $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);
        $claim = $this->jobs->claimNext();
        self::assertIsArray($claim);
        $this->jobs->ensureParts($claim, $this->service->partPlan(
            $this->supplierId,
            PayrollPeriodExportScope::Monthly,
            '2097-08-01',
            '2097-08-31',
        ));
        $part = $this->jobs->claimPart($claim);
        self::assertIsArray($part);
        self::assertSame('document', $part['part_kind']);
        $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_part_attempts
                SET attempt_no = ?
              WHERE supplier_id = ? AND job_part_id = ? AND lease_token = UNHEX(?)',
        )->execute([
            PayrollPeriodExportJobRepository::MAX_ATTEMPTS,
            $this->supplierId,
            (int) $part['id'],
            (string) $part['lease_token'],
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_parts
                SET attempt_count = ?, locked_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            PayrollPeriodExportJobRepository::MAX_ATTEMPTS,
            $this->supplierId,
            (int) $part['id'],
        ]);

        self::assertNull($this->jobs->claimPart($claim));
        $failed = $this->queue->detail($this->supplierId, (int) $queued['id']);
        self::assertIsArray($failed);
        self::assertSame('failed', $failed['status']);
        self::assertSame('worker_lease_expired', $failed['last_error_code']);
        self::assertSame('failed', $this->jobAttemptStatus((int) $queued['id'], (int) $claim['attempt_count']));
        self::assertSame('failed', $this->partStatus((int) $part['id']));
        self::assertSame(1, $this->countRows(
            'payroll_period_export_job_parts WHERE job_id = ' . (int) $queued['id']
            . ' AND part_kind <> "archive" AND status = "queued"',
        ));
    }

    public function testFrozenPartPlanRejectsAdditionalSource(): void
    {
        $this->requirePartsMigration();
        $this->approvedRevision(1);
        $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);
        $claim = $this->jobs->claimNext();
        self::assertIsArray($claim);
        $plan = $this->service->partPlan(
            $this->supplierId,
            PayrollPeriodExportScope::Monthly,
            '2097-08-01',
            '2097-08-31',
        );
        $this->jobs->ensureParts($claim, $plan);
        array_unshift($plan, [
            'part_key' => hash('sha256', 'synthetic-plan-conflict'),
            'part_kind' => 'document',
            'source_id' => 999999999,
            'source_sha256' => hash('sha256', 'synthetic-plan-conflict-bytes'),
            'source_size_bytes' => 29,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->jobs->ensureParts($claim, $plan);
    }

    public function testParentLeaseConflictRollsBackCompletedPart(): void
    {
        $this->requirePartsMigration();
        $this->approvedRevision(1);
        $export = $this->service->createMonthly($this->supplierId, '2097-08', $this->userId);
        $this->queue->enqueueMonthly($this->supplierId, '2097-08', $this->userId);
        $claim = $this->jobs->claimNext();
        self::assertIsArray($claim);
        $this->jobs->ensureParts($claim, $this->service->partPlan(
            $this->supplierId,
            PayrollPeriodExportScope::Monthly,
            '2097-08-01',
            '2097-08-31',
        ));
        $part = $this->jobs->claimPart($claim);
        self::assertIsArray($part);
        self::assertSame('archive', $part['part_kind']);
        $conflictingClaim = $claim;
        $conflictingClaim['lease_token'] = bin2hex(random_bytes(16));

        try {
            $this->jobs->completeArchivePartAndJob($conflictingClaim, $part, (int) $export['id']);
            self::fail('Konflikt parent lease musí atomickou finalizaci odmítnout.');
        } catch (\RuntimeException) {
            self::assertSame('processing', $this->partStatus((int) $part['id']));
        }

        self::assertTrue($this->jobs->failPartAndRelease(
            $claim,
            $part,
            'synthetic_after_complete_race',
            'Synthetic parent race after part completion update.',
        ));
        self::assertSame('retry_wait', $this->partStatus((int) $part['id']));

        $this->makePartRetryAvailable((int) $claim['id']);
        $retryClaim = $this->jobs->claimNext();
        self::assertIsArray($retryClaim);
        $retryPart = $this->jobs->claimPart($retryClaim);
        self::assertIsArray($retryPart);
        $this->jobs->completeArchivePartAndJob($retryClaim, $retryPart, (int) $export['id']);
        self::assertNull($this->jobs->failPartAndRelease(
            $retryClaim,
            $retryPart,
            'synthetic_late_catch',
            'Synthetic catch after committed completion.',
        ));
        self::assertSame('completed', $this->partStatus((int) $retryPart['id']));
        self::assertSame('completed', $this->queue->detail(
            $this->supplierId,
            (int) $retryClaim['id'],
        )['status'] ?? null);
    }

    public function testQueuedExportSurvivesDeletionOfRequestingUser(): void
    {
        if (!$this->db->hasTable('payroll_period_export_jobs')) {
            self::fail('Migrace 1604 neproběhla.');
        }
        $this->approvedRevision(1);
        $pdo = $this->db->pdo();
        $email = 'payroll-export-' . bin2hex(random_bytes(8)) . '@example.test';
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, role_id, locale, is_active)
             SELECT ?, password_hash, ?, role, role_id, locale, 1
               FROM users
              WHERE id = ?',
        )->execute([$email, 'Syntetický autor exportu', $this->userId]);
        $requestingUserId = (int) $pdo->lastInsertId();

        $queued = $this->queue->enqueueMonthly(
            $this->supplierId,
            '2097-08',
            $requestingUserId,
        );
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$requestingUserId]);
        $jobStatement = $pdo->prepare(
            'SELECT requested_by FROM payroll_period_export_jobs WHERE id = ?',
        );
        $jobStatement->execute([(int) $queued['id']]);
        $jobRow = $jobStatement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($jobRow);
        self::assertNull($jobRow['requested_by']);

        self::assertSame(
            ['processed' => 1, 'succeeded' => 1, 'failed' => 0],
            $this->queue->processAvailable(),
        );
        $completed = $this->queue->detail(
            $this->supplierId,
            (int) $queued['id'],
        );
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertIsInt($completed['export_id']);
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

    /** @return array{file_sha256:string,size_bytes:int,storage_key:string} */
    private function archiveDocument(
        int $runId,
        int $revisionId,
        int $employeeId,
        string $revisionHash,
        string $bytes,
        string $key,
    ): array {
        $stored = $this->documentStorage->store($this->supplierId, $bytes);
        $this->documents->insertOrGet([
            'supplier_id' => $this->supplierId,
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'employee_id' => $employeeId,
            'document_kind' => 'payslip',
            'document_revision_no' => 1,
            'supersedes_document_id' => null,
            'source_snapshot_hash' => hash('sha256', $key . ':source'),
            'revision_snapshot_hash' => $revisionHash,
            'template_version' => 'synthetic-v1',
            'renderer_version' => 'synthetic-v1',
            'file_sha256' => $stored['file_sha256'],
            'size_bytes' => $stored['size_bytes'],
            'mime_type' => 'application/pdf',
            'storage_key' => $stored['storage_key'],
            'suggested_filename' => $key . '.pdf',
            'manifest_json' => null,
            'idempotency_key_hash' => hash('sha256', $key),
            'created_by' => null,
        ]);

        return $stored;
    }

    private function requirePartsMigration(): void
    {
        if (!$this->db->hasTable('payroll_period_export_job_parts')) {
            self::fail('Migrace 1606 neproběhla.');
        }
    }

    private function makePartRetryAvailable(int $jobId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_job_parts
                SET available_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND
              WHERE supplier_id = ? AND job_id = ? AND status = "retry_wait"',
        )->execute([$this->supplierId, $jobId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_jobs
                SET available_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND
              WHERE supplier_id = ? AND id = ? AND status = "retry_wait"',
        )->execute([$this->supplierId, $jobId]);
    }

    private function partStatus(int $partId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_period_export_job_parts WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $partId]);
        $status = $statement->fetchColumn();
        self::assertIsString($status);

        return $status;
    }

    private function jobAttemptStatus(int $jobId, int $attemptNo): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_period_export_job_attempts
              WHERE supplier_id = ? AND job_id = ? AND attempt_no = ?',
        );
        $statement->execute([$this->supplierId, $jobId, $attemptNo]);
        $status = $statement->fetchColumn();
        self::assertIsString($status);

        return $status;
    }

    private function integer(string $sql): int
    {
        $statement = $this->db->pdo()->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
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

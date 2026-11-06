<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollArtifact;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollEmploymentExitDocumentSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testExitRevisionIsAnImmutableThirdDocumentAnchor(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        self::assertTrue(
            $connection->hasTable('payroll_employment_exit_revisions'),
        );
        $columns = $connection->pdo()->query(
            'SHOW COLUMNS FROM payroll_generated_documents',
        );
        self::assertNotFalse($columns);
        self::assertContains(
            'employment_exit_revision_id',
            $columns->fetchAll(\PDO::FETCH_COLUMN),
        );
        $triggers = $connection->pdo()->query('SHOW TRIGGERS');
        self::assertNotFalse($triggers);
        $triggerNames = $triggers->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains(
            'trg_payroll_employment_exit_revision_immutable_update',
            $triggerNames,
        );
        self::assertContains(
            'trg_payroll_employment_exit_revision_validate_insert',
            $triggerNames,
        );

        $connection->close();
    }

    public function testExitDocumentArchivesWithoutFakeMonthlyOrAnnualAnchor(): void
    {
        $previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $dataDir = sys_get_temp_dir()
            . '/myucto-payroll-employment-exit-'
            . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $dataDir);

        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $service = $container->get(PayrollDocumentService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentService::class, $service);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, "Syntetická výstupní osoba", "employee", 0)'
            )->execute([$supplierId]);
            $employeeId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_employments
                    (supplier_id, employee_id, code, relation_type, status,
                     start_date, end_date, is_legacy_projection)
                 VALUES (?, ?, "synthetic-exit", "employment", "ended",
                         "2024-01-01", "2026-07-31", 0)'
            )->execute([$supplierId, $employeeId]);
            $employmentId = (int) $pdo->lastInsertId();
            $snapshotHash = str_repeat('a', 64);
            $manifest = '{"schema_version":"synthetic-employment-exit.v1"}';
            $pdo->prepare(
                'INSERT INTO payroll_employment_exit_revisions
                    (supplier_id, employee_id, employment_id, purpose,
                     employment_end_date, revision_no, snapshot_ciphertext,
                     snapshot_hash, source_manifest_json,
                     source_manifest_hash, approved_at)
                 VALUES (?, ?, ?, "employment_certificate", "2026-07-31",
                         1, "enc:v2:synthetic", ?, ?, ?, NOW())'
            )->execute([
                $supplierId,
                $employeeId,
                $employmentId,
                $snapshotHash,
                $manifest,
                hash('sha256', $manifest),
            ]);
            $exitRevisionId = (int) $pdo->lastInsertId();
            $artifact = new PayrollArtifact(
                PayrollDocumentKind::EmploymentCertificate,
                '%PDF-1.4 synthetic employment certificate',
                'application/pdf',
                'synthetic-employment-certificate.pdf',
                $snapshotHash,
                'synthetic-v1',
                'synthetic-v1',
            );

            $document = $service->archiveEmploymentExitPdf(
                $supplierId,
                $exitRevisionId,
                $employeeId,
                $artifact,
                'synthetic-employment-exit-document',
                null,
            );

            self::assertNull($document['run_id']);
            self::assertNull($document['revision_id']);
            self::assertNull($document['annual_revision_id']);
            self::assertSame(
                $exitRevisionId,
                $document['employment_exit_revision_id'],
            );
            self::assertSame(
                $document['id'],
                $service->archiveEmploymentExitPdf(
                    $supplierId,
                    $exitRevisionId,
                    $employeeId,
                    $artifact,
                    'synthetic-employment-exit-document',
                    null,
                )['id'],
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
            self::removeDirectory($dataDir);
            $previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $previousDataDir);
        }
    }

    private static function removeDirectory(string $path): void
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
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

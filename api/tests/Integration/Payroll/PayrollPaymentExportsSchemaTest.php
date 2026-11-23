<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollPaymentExportsSchemaTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testImmutableExportArchiveAndUserBoundGrantSchemaIsInstalled(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        foreach ([
            'payroll_payment_exports',
            'payroll_payment_export_download_grants',
        ] as $table) {
            self::assertTrue($connection->hasTable($table), "Missing table {$table}.");
        }

        $pdo = $connection->pdo();
        $columns = $this->query(
            $pdo,
            'SHOW COLUMNS FROM payroll_payment_exports',
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'supplier_id',
            'batch_id',
            'export_format',
            'export_revision_no',
            'supersedes_export_id',
            'source_snapshot_hash',
            'file_sha256',
            'storage_key',
            'idempotency_key_hash',
        ] as $column) {
            self::assertContains($column, $columns);
        }

        $exportCreate = (string) $this->query(
            $pdo,
            'SHOW CREATE TABLE payroll_payment_exports',
        )->fetch(PDO::FETCH_NUM)[1];
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `batch_id`)',
            $exportCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `supersedes_export_id`)',
            $exportCreate,
        );
        self::assertStringContainsString(
            '`storage_key` = `file_sha256`',
            $exportCreate,
        );

        $grantCreate = (string) $this->query(
            $pdo,
            'SHOW CREATE TABLE payroll_payment_export_download_grants',
        )->fetch(PDO::FETCH_NUM)[1];
        self::assertStringContainsString(
            'FOREIGN KEY (`supplier_id`, `export_id`)',
            $grantCreate,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (`user_id`)',
            $grantCreate,
        );
        self::assertStringNotContainsString('token', str_replace(
            ['token_hash', 'uq_payroll_payment_export_grant_token'],
            ['', ''],
            strtolower($grantCreate),
        ));

        $triggers = $this->query($pdo, 'SHOW TRIGGERS')
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'trg_payroll_payment_export_validate_insert',
            'trg_payroll_payment_export_immutable_update',
            'trg_payroll_payment_export_immutable_delete',
            'trg_payroll_payment_export_grant_validate_insert',
            'trg_payroll_payment_export_grant_consume_update',
        ] as $trigger) {
            self::assertContains($trigger, $triggers);
        }

        $connection->close();
    }

    public function testExportRevisionTenantIntegrityAndOneUseGrantAreEnforced(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM supplier',
        )->fetchColumn();
        $userId = (int) $this->query(
            $pdo,
            'SELECT MIN(id) FROM users',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $userId);

        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            $otherSupplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            [$batchId, $batchSnapshotHash] = $this->insertBatch(
                $pdo,
                $supplierId,
                'primary',
            );
            [$otherBatchId] = $this->insertBatch(
                $pdo,
                $otherSupplierId,
                'other',
            );

            $firstExportId = $this->insertExport(
                $pdo,
                $supplierId,
                $batchId,
                1,
                null,
                $batchSnapshotHash,
                'first',
            );
            $secondExportId = $this->insertExport(
                $pdo,
                $supplierId,
                $batchId,
                2,
                $firstExportId,
                $batchSnapshotHash,
                'second',
            );
            self::assertGreaterThan($firstExportId, $secondExportId);

            $this->assertDatabaseFailure(
                fn () => $this->insertExport(
                    $pdo,
                    $supplierId,
                    $batchId,
                    2,
                    $firstExportId,
                    $batchSnapshotHash,
                    'fork',
                ),
                'Duplicate entry',
            );
            $this->assertDatabaseFailure(
                fn () => $this->insertExport(
                    $pdo,
                    $otherSupplierId,
                    $otherBatchId,
                    2,
                    $firstExportId,
                    hash('sha256', 'synthetic-payment-batch-other'),
                    'cross-tenant',
                ),
                'Payroll payment export revision chain is inconsistent',
            );
            $this->assertDatabaseFailure(
                fn () => $this->insertExport(
                    $pdo,
                    $supplierId,
                    $batchId,
                    3,
                    $secondExportId,
                    hash('sha256', 'another-snapshot'),
                    'wrong-snapshot',
                ),
                'Payroll payment export source snapshot differs from batch',
            );
            $this->assertDatabaseFailure(
                fn () => $pdo->exec(
                    "UPDATE payroll_payment_exports
                        SET mime_type = mime_type
                      WHERE supplier_id = {$supplierId}
                        AND id = {$firstExportId}",
                ),
                'Payroll payment exports are immutable',
            );
            $this->assertDatabaseFailure(
                fn () => $pdo->exec(
                    "DELETE FROM payroll_payment_exports
                      WHERE supplier_id = {$supplierId}
                        AND id = {$firstExportId}",
                ),
                'Payroll payment exports are immutable',
            );

            $this->assertDatabaseFailure(
                fn () => $this->insertGrant(
                    $pdo,
                    $supplierId,
                    $firstExportId,
                    $userId,
                    'too-short',
                    29,
                ),
                'Payroll payment export grant TTL must be between 30 and 900 seconds',
            );
            $this->assertDatabaseFailure(
                fn () => $this->insertGrant(
                    $pdo,
                    $supplierId,
                    $firstExportId,
                    $userId,
                    'too-long',
                    901,
                ),
                'Payroll payment export grant TTL must be between 30 and 900 seconds',
            );
            $this->assertDatabaseFailure(
                fn () => $this->insertGrant(
                    $pdo,
                    $otherSupplierId,
                    $firstExportId,
                    $userId,
                    'cross-tenant',
                    300,
                ),
                'Payroll payment export grant target does not belong to supplier',
            );

            $grantId = $this->insertGrant(
                $pdo,
                $supplierId,
                $firstExportId,
                $userId,
                'valid',
                300,
            );
            $consume = $pdo->prepare(
                'UPDATE payroll_payment_export_download_grants
                    SET used_at = NOW()
                  WHERE id = ? AND supplier_id = ? AND export_id = ?
                    AND user_id = ? AND used_at IS NULL
                    AND expires_at >= NOW()',
            );
            $consume->execute([
                $grantId,
                $supplierId,
                $firstExportId,
                $userId,
            ]);
            self::assertSame(1, $consume->rowCount());
            $consume->execute([
                $grantId,
                $supplierId,
                $firstExportId,
                $userId,
            ]);
            self::assertSame(0, $consume->rowCount());

            $this->assertDatabaseFailure(
                fn () => $pdo->exec(
                    "UPDATE payroll_payment_export_download_grants
                        SET expires_at = DATE_ADD(expires_at, INTERVAL 1 SECOND)
                      WHERE id = {$grantId}",
                ),
                'Payroll payment export grant only allows one-time consumption',
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /**
     * @return array{int, string}
     */
    private function insertBatch(
        PDO $pdo,
        int $supplierId,
        string $scope,
    ): array {
        $snapshotHash = hash(
            'sha256',
            "synthetic-payment-batch-{$scope}",
        );
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "abo", "outgoing", "2099-01-10", "CZK",
                     ?, 10000, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            "synthetic-export-batch-{$scope}",
            "payer:synthetic:{$scope}",
            "enc:v2:synthetic-export-batch-{$scope}",
            $snapshotHash,
            hash('sha256', "synthetic-export-batch-{$scope}", true),
        ]);

        return [(int) $pdo->lastInsertId(), $snapshotHash];
    }

    private function insertExport(
        PDO $pdo,
        int $supplierId,
        int $batchId,
        int $revisionNo,
        ?int $supersedesExportId,
        string $sourceSnapshotHash,
        string $scope,
    ): int {
        $bytes = "synthetic payroll payment export {$scope}";
        $fileHash = hash('sha256', $bytes);
        $pdo->prepare(
            'INSERT INTO payroll_payment_exports
                (supplier_id, batch_id, export_format, export_revision_no,
                 supersedes_export_id, source_snapshot_hash, exporter_version,
                 file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, "abo", ?, ?, ?, "synthetic-v1", ?, ?,
                     "text/plain", ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            $revisionNo,
            $supersedesExportId,
            $sourceSnapshotHash,
            $fileHash,
            strlen($bytes),
            $fileHash,
            "synthetic-payment-{$scope}.kpc",
            hash('sha256', "synthetic-payment-export-{$scope}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertGrant(
        PDO $pdo,
        int $supplierId,
        int $exportId,
        int $userId,
        string $scope,
        int $ttlSeconds,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_payment_export_download_grants
                (supplier_id, export_id, user_id, token_hash,
                 expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
        )->execute([
            $supplierId,
            $exportId,
            $userId,
            hash('sha256', "synthetic-payment-grant-{$scope}", true),
            $ttlSeconds,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertDatabaseFailure(
        callable $operation,
        string $message,
    ): void {
        try {
            $operation();
            self::fail("Expected database failure containing: {$message}");
        } catch (PDOException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function query(PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}

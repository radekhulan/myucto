<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionInboxStorageSettingsService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SubmissionInboxStorageSettingsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionInboxStorageSettingsService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(SubmissionInboxStorageSettingsService::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $templateSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $templateSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $templateSupplierId);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSettingsAreSeparatedBySupplierAndEnvironment(): void
    {
        $productionFolderId = $this->createFolder($this->supplierId, 'Produkce');
        $testFolderId = $this->createFolder($this->supplierId, 'Test');

        $production = $this->service->save($this->supplierId, 'production', $productionFolderId, 0, $this->userId);
        $test = $this->service->save($this->supplierId, 'test', $testFolderId, 0, $this->userId);

        self::assertSame($productionFolderId, $production['base_folder_id']);
        self::assertSame($testFolderId, $test['base_folder_id']);
        self::assertCount(2, $this->service->list($this->supplierId));
        self::assertSame([], $this->service->list($this->otherSupplierId));
    }

    public function testUnsetSettingArchivesIntoOwnFolderNotDocumentsRoot(): void
    {
        $header = new InboxMessageHeader(
            'zprava-1',
            deliveredAt: new \DateTimeImmutable('2026-08-30 09:15:00'),
        );

        $folderId = $this->service->resolveFolder($this->supplierId, 'production', $header, $this->userId);

        self::assertNotNull($folderId);
        self::assertSame(
            ['Datová schránka', '2026', '08', '30', 'zprava-1'],
            $this->folderPath((int) $folderId),
        );

        $testFolderId = $this->service->resolveFolder($this->supplierId, 'test', $header, $this->userId);
        self::assertNotNull($testFolderId);
        self::assertSame('Datová schránka (testovací provoz)', $this->folderPath((int) $testFolderId)[0]);
    }

    public function testChosenFolderStaysTheRootWithoutExtraLevel(): void
    {
        $chosen = $this->createFolder($this->supplierId, 'Vlastní archiv');
        $this->service->save($this->supplierId, 'production', $chosen, 0, $this->userId);

        $folderId = $this->service->resolveFolder(
            $this->supplierId,
            'production',
            new InboxMessageHeader('zprava-2', deliveredAt: new \DateTimeImmutable('2026-08-30 09:15:00')),
            $this->userId,
        );

        self::assertSame(
            ['Vlastní archiv', '2026', '08', '30', 'zprava-2'],
            $this->folderPath((int) $folderId),
        );
    }

    /** @return list<string> */
    private function folderPath(int $folderId): array
    {
        $path = [];
        $current = $folderId;
        while ($current > 0) {
            $row = $this->db->pdo()->prepare('SELECT name, parent_id FROM document_folders WHERE id = ?');
            $row->execute([$current]);
            $found = $row->fetch();
            if ($found === false) {
                break;
            }
            array_unshift($path, (string) $found['name']);
            $current = (int) ($found['parent_id'] ?? 0);
        }
        return $path;
    }

    public function testForeignAndDeletedFoldersAreRejected(): void
    {
        $foreignFolderId = $this->createFolder($this->otherSupplierId, 'Cizí');
        try {
            $this->service->save($this->supplierId, 'test', $foreignFolderId, 0, $this->userId);
            self::fail('Cizí složka měla být odmítnuta.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_inbox_archive_folder_not_found', $e->errorCode);
        }

        $deletedFolderId = $this->createFolder($this->supplierId, 'V koši');
        $this->db->pdo()->prepare('UPDATE document_folders SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$deletedFolderId]);
        try {
            $this->service->save($this->supplierId, 'test', $deletedFolderId, 0, $this->userId);
            self::fail('Smazaná složka měla být odmítnuta.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_inbox_archive_folder_not_found', $e->errorCode);
        }
    }

    public function testDatabaseGuardRejectsForeignFolderEvenWithoutServiceValidation(): void
    {
        $foreignFolderId = $this->createFolder($this->otherSupplierId, 'Cizí DB');
        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO submission_inbox_storage_settings
                (supplier_id, channel, environment, base_folder_id, updated_by)
             VALUES (?, \'isds\', \'test\', ?, ?)'
        )->execute([$this->supplierId, $foreignFolderId, $this->userId]);
    }

    public function testStaleVersionCannotOverwriteNewerSettingAndClearIsExplicit(): void
    {
        $firstFolderId = $this->createFolder($this->supplierId, 'První');
        $secondFolderId = $this->createFolder($this->supplierId, 'Druhá');
        $saved = $this->service->save($this->supplierId, 'test', $firstFolderId, 0, $this->userId);
        $updated = $this->service->save(
            $this->supplierId,
            'test',
            $secondFolderId,
            (int) $saved['row_version'],
            $this->userId,
        );

        try {
            $this->service->save(
                $this->supplierId,
                'test',
                $firstFolderId,
                (int) $saved['row_version'],
                $this->userId,
            );
            self::fail('Zastaralá verze měla být odmítnuta.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_inbox_archive_settings_conflict', $e->errorCode);
        }

        self::assertTrue($this->service->clear($this->supplierId, 'test', (int) $updated['row_version']));
        self::assertSame([], $this->service->list($this->supplierId));
    }

    private function createFolder(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_folders (supplier_id, parent_id, name, created_by) VALUES (?, NULL, ?, ?)'
        );
        $stmt->execute([$supplierId, $name, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}

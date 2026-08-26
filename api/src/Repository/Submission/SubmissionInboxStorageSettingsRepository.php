<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class SubmissionInboxStorageSettingsRepository
{
    private const TABLE = 'submission_inbox_storage_settings';

    private const COLUMNS = 'supplier_id, channel, environment, base_folder_id,
        row_version, updated_by, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = \'isds\'
              ORDER BY environment ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $environment): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = \'isds\' AND environment = ?'
        );
        $stmt->execute([$supplierId, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    public function insert(int $supplierId, string $environment, int $baseFolderId, ?int $userId): void
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, channel, environment, base_folder_id, updated_by)
             VALUES (?, \'isds\', ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $environment, $baseFolderId, $userId]);
    }

    public function update(int $supplierId, string $environment, int $baseFolderId, int $expectedVersion, ?int $userId): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET base_folder_id = ?, updated_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND channel = \'isds\' AND environment = ? AND row_version = ?'
        );
        $stmt->execute([$baseFolderId, $userId, $supplierId, $environment, $expectedVersion]);
        return $stmt->rowCount() === 1;
    }

    public function delete(int $supplierId, string $environment, int $expectedVersion): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = \'isds\' AND environment = ? AND row_version = ?'
        );
        $stmt->execute([$supplierId, $environment, $expectedVersion]);
        return $stmt->rowCount() === 1;
    }

    private function assertAvailable(): void
    {
        if (!$this->db->hasTable(self::TABLE)) {
            throw new \DomainException('Nastavení archivu příchozí ISDS není k dispozici (chybí migrace 1573).');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalize(array $row): array
    {
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['base_folder_id'] = (int) $row['base_folder_id'];
        $row['row_version'] = (int) $row['row_version'];
        $row['updated_by'] = $row['updated_by'] !== null ? (int) $row['updated_by'] : null;
        return $row;
    }
}

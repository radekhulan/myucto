<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class ExternalEntityMapRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findExternal(int $supplierId, int $connectionId, string $entityType, string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM external_entity_map
            WHERE supplier_id = ? AND connection_id = ? AND entity_type = ? AND external_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $connectionId, $entityType, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findInternal(int $supplierId, int $connectionId, string $entityType, int $internalId, ?string $internalSubId = null): ?array
    {
        $sql = 'SELECT * FROM external_entity_map WHERE supplier_id = ? AND connection_id = ?
            AND entity_type = ? AND internal_id = ? AND internal_sub_id ' . ($internalSubId === null ? 'IS NULL' : '= ?') . ' LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($internalSubId === null
            ? [$supplierId, $connectionId, $entityType, $internalId]
            : [$supplierId, $connectionId, $entityType, $internalId, $internalSubId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function put(
        int $supplierId,
        int $connectionId,
        string $sourceKey,
        string $entityType,
        string $externalId,
        int $internalId,
        ?string $externalParentId = null,
        ?string $internalSubId = null,
        ?int $externalVersion = null,
    ): array {
        foreach ([$entityType, $externalId] as $value) {
            if ($value === '' || strlen($value) > 255) {
                throw new \InvalidArgumentException('Neplatná externí identita.');
            }
        }
        if ($sourceKey === '' || strlen($sourceKey) > 100) {
            throw new \InvalidArgumentException('Neplatný zdroj externí identity.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,59}$/D', $entityType)) {
            throw new \InvalidArgumentException('Neplatný typ externí identity.');
        }
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $connection = $pdo->prepare('SELECT id FROM integration_connections
                WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $connection->execute([$supplierId, $connectionId]);
            if ($connection->fetchColumn() === false) {
                throw new \RuntimeException('integration_connection_not_found');
            }
            $existing = $this->findExternalForUpdate($supplierId, $connectionId, $entityType, $externalId);
            if ($existing === null) {
                $stmt = $pdo->prepare('INSERT INTO external_entity_map
                    (supplier_id, connection_id, source_key, entity_type, external_id, external_parent_id,
                     internal_id, internal_sub_id, external_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                try {
                    $stmt->execute([$supplierId, $connectionId, $sourceKey, $entityType, $externalId,
                        $externalParentId, $internalId, $internalSubId, $externalVersion]);
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }
                    $existing = $this->findExternalForUpdate($supplierId, $connectionId, $entityType, $externalId);
                    if ($existing === null) {
                        throw $e;
                    }
                    $this->updateExisting($existing, $sourceKey, $externalParentId, $internalId, $internalSubId, $externalVersion);
                }
            } else {
                $this->updateExisting($existing, $sourceKey, $externalParentId, $internalId, $internalSubId, $externalVersion);
            }
            $result = $this->findExternalForUpdate($supplierId, $connectionId, $entityType, $externalId)
                ?? throw new \RuntimeException('Externí identitu se nepodařilo uložit.');
            if ($ownTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function findExternalForUpdate(int $supplierId, int $connectionId, string $entityType, string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM external_entity_map
            WHERE supplier_id = ? AND connection_id = ? AND entity_type = ? AND external_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$supplierId, $connectionId, $entityType, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    private function updateExisting(
        array $existing,
        string $sourceKey,
        ?string $externalParentId,
        int $internalId,
        ?string $internalSubId,
        ?int $externalVersion,
    ): void {
        if ($externalVersion !== null && $existing['external_version'] !== null) {
            if ($externalVersion < $existing['external_version']) {
                return;
            }
            if ($externalVersion === $existing['external_version']) {
                if ($existing['source_key'] === $sourceKey
                    && $existing['external_parent_id'] === $externalParentId
                    && $existing['internal_id'] === $internalId
                    && $existing['internal_sub_id'] === $internalSubId) {
                    return;
                }
                throw new \RuntimeException('external_entity_map_version_conflict');
            }
        }
        $stmt = $this->db->pdo()->prepare('UPDATE external_entity_map SET source_key = ?, external_parent_id = ?,
            internal_id = ?, internal_sub_id = ?, external_version = ? WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$sourceKey, $externalParentId, $internalId, $internalSubId, $externalVersion,
            $existing['id'], $existing['supplier_id']]);
    }

    private function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'connection_id', 'internal_id', 'external_version'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        unset($row['connection_scope_id']);
        return $row;
    }
}

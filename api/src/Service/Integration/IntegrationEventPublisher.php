<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDOException;

final class IntegrationEventPublisher
{
    public function __construct(private readonly Connection $db) {}

    public function publish(
        int $supplierId,
        string $entityType,
        string $entityId,
        string $eventType,
        int $aggregateVersion,
        array $payload,
        ?string $idempotencyKey = null,
    ): int {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Integrační událost musí vzniknout uvnitř doménové transakce.');
        }
        if ($supplierId < 1 || $entityId === '' || $aggregateVersion < 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,59}$/D', $entityType) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $eventType) !== 1) {
            throw new \InvalidArgumentException('Neplatná integrační událost.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $key = $idempotencyKey ?? implode(':', [$entityType, $entityId, $aggregateVersion, $eventType]);
        $connections = $pdo->prepare("SELECT id FROM integration_connections WHERE supplier_id = ? AND status = 'active' ORDER BY id");
        $connections->execute([$supplierId]);
        $count = 0;
        foreach ($connections->fetchAll(\PDO::FETCH_COLUMN) as $connectionId) {
            try {
                $stmt = $pdo->prepare('INSERT INTO integration_outbox
                    (event_uuid, supplier_id, connection_id, entity_type, entity_id, event_type,
                     aggregate_version, idempotency_key, payload_json, payload_sha256)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([self::uuid(), $supplierId, (int) $connectionId, $entityType, $entityId,
                    $eventType, $aggregateVersion, $key, $json, $hash]);
                $count++;
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                $existing = $pdo->prepare('SELECT entity_type, entity_id, event_type, aggregate_version, payload_sha256
                    FROM integration_outbox WHERE connection_id = ? AND idempotency_key = ? LIMIT 1');
                $existing->execute([(int) $connectionId, $key]);
                $row = $existing->fetch(\PDO::FETCH_ASSOC);
                if ($row === false) {
                    $existing = $pdo->prepare('SELECT entity_type, entity_id, event_type, aggregate_version, payload_sha256
                        FROM integration_outbox WHERE connection_id = ? AND entity_type = ? AND entity_id = ?
                          AND aggregate_version = ? AND event_type = ? LIMIT 1');
                    $existing->execute([(int) $connectionId, $entityType, $entityId, $aggregateVersion, $eventType]);
                    $row = $existing->fetch(\PDO::FETCH_ASSOC);
                }
                if ($row === false || $row['entity_type'] !== $entityType || $row['entity_id'] !== $entityId
                    || $row['event_type'] !== $eventType || (int) $row['aggregate_version'] !== $aggregateVersion
                    || !hash_equals((string) $row['payload_sha256'], $hash)) {
                    throw new \RuntimeException('integration_outbox_idempotency_conflict');
                }
            }
        }
        return $count;
    }

    public function catalogChanged(int $supplierId, int $stockItemId, string $sourceArea = 'reservation', bool $tombstone = false): int
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Změna katalogu musí vzniknout uvnitř doménové transakce.');
        }
        if (!in_array($sourceArea, ['product', 'price', 'media', 'i18n', 'availability', 'reservation'], true)) {
            throw new \InvalidArgumentException('Neplatná oblast změny katalogu.');
        }
        $stmt = $this->db->pdo()->prepare('INSERT INTO integration_change_log
            (supplier_id, entity_id, change_type, source_area) VALUES (?, ?, ?, ?)');
        $stmt->execute([$supplierId, $stockItemId, $tombstone ? 'tombstone' : 'upsert', $sourceArea]);
        $cursor = $this->db->pdo()->prepare('SELECT last_cursor FROM integration_change_state WHERE supplier_id = ?');
        $cursor->execute([$supplierId]);
        return (int) $cursor->fetchColumn();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

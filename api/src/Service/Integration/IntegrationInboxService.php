<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class IntegrationInboxService
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly Connection $db) {}

    public function accept(int $supplierId, int $connectionId, array $event, string $rawPayload): array
    {
        foreach (['event_id', 'entity_type', 'entity_id', 'event_type', 'aggregate_version'] as $key) {
            if (!array_key_exists($key, $event)) {
                throw new \InvalidArgumentException('Webhook nemá povinné pole ' . $key . '.');
            }
        }
        $eventId = trim((string) $event['event_id']);
        $entityType = trim((string) $event['entity_type']);
        $entityId = trim((string) $event['entity_id']);
        $eventType = trim((string) $event['event_type']);
        $version = (int) $event['aggregate_version'];
        if ($eventId === '' || strlen($eventId) > 190 || $entityId === '' || strlen($entityId) > 190
            || $version < 1 || preg_match('/^[a-z][a-z0-9_.-]{0,59}$/D', $entityType) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $eventType) !== 1) {
            throw new \InvalidArgumentException('Webhook má neplatnou identitu nebo verzi.');
        }
        try {
            $stmt = $this->db->pdo()->prepare('INSERT INTO integration_inbox
                (supplier_id, connection_id, external_event_id, entity_type, entity_id, event_type,
                 aggregate_version, payload_json, payload_sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $connectionId, $eventId, $entityType, $entityId, $eventType,
                $version, $rawPayload, hash('sha256', $rawPayload)]);
            return ['accepted' => true, 'duplicate' => false, 'id' => (int) $this->db->pdo()->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $existing = $this->db->pdo()->prepare('SELECT id, payload_sha256 FROM integration_inbox
                WHERE connection_id = ? AND external_event_id = ?');
            $existing->execute([$connectionId, $eventId]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !hash_equals((string) $row['payload_sha256'], hash('sha256', $rawPayload))) {
                throw new \RuntimeException('webhook_idempotency_conflict');
            }
            return ['accepted' => true, 'duplicate' => true, 'id' => (int) $row['id']];
        }
    }

    public function process(int $supplierId, int $connectionId, int $id, callable $handler): string
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Inbox processing vyžaduje samostatnou transakci.');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM integration_inbox WHERE supplier_id = ? AND connection_id = ?
                AND id = ? AND status IN ('queued','retry') AND available_at <= NOW(6) FOR UPDATE");
            $stmt->execute([$supplierId, $connectionId, $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->commit();
                return 'unavailable';
            }
            $state = $pdo->prepare('SELECT aggregate_version FROM integration_inbox_state
                WHERE supplier_id = ? AND connection_id = ? AND entity_type = ? AND entity_id = ? FOR UPDATE');
            $state->execute([$supplierId, $connectionId, $row['entity_type'], $row['entity_id']]);
            $current = $state->fetchColumn();
            if ($current !== false && (int) $row['aggregate_version'] <= (int) $current) {
                $pdo->prepare("UPDATE integration_inbox SET status = 'ignored', processed_at = NOW(6),
                    last_error_code = 'stale_version' WHERE id = ?")->execute([$id]);
                $pdo->commit();
                return 'ignored';
            }
            $pdo->prepare("UPDATE integration_inbox SET status = 'processing', attempts = attempts + 1 WHERE id = ?")
                ->execute([$id]);
            $handler(json_decode((string) $row['payload_json'], true, 128, JSON_THROW_ON_ERROR), $row);
            $pdo->prepare('INSERT INTO integration_inbox_state
                (supplier_id, connection_id, entity_type, entity_id, aggregate_version)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE aggregate_version = VALUES(aggregate_version)')
                ->execute([$supplierId, $connectionId, $row['entity_type'], $row['entity_id'], $row['aggregate_version']]);
            $pdo->prepare("UPDATE integration_inbox SET status = 'processed', processed_at = NOW(6), last_error_code = NULL WHERE id = ?")
                ->execute([$id]);
            $pdo->commit();
            return 'processed';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->fail($supplierId, $connectionId, $id, 'processing_failed');
            throw $e;
        }
    }

    private function fail(int $supplierId, int $connectionId, int $id, string $errorCode): void
    {
        $stmt = $this->db->pdo()->prepare("UPDATE integration_inbox SET
            status = IF(attempts + 1 >= ?, 'dead_letter', 'retry'), attempts = attempts + 1,
            available_at = DATE_ADD(NOW(6), INTERVAL LEAST(3600, POW(2, attempts) + MOD(id, 17)) SECOND),
            last_error_code = ? WHERE supplier_id = ? AND connection_id = ? AND id = ?");
        $stmt->execute([self::MAX_ATTEMPTS, $errorCode, $supplierId, $connectionId, $id]);
    }
}

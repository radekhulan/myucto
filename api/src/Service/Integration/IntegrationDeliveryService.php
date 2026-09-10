<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class IntegrationDeliveryService
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly Connection $db) {}

    public function claimOutbox(int $supplierId, int $connectionId, int $leaseSeconds = 60): ?array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Claim vyžaduje samostatnou transakci.');
        }
        $pdo->beginTransaction();
        try {
            $connection = $pdo->prepare("SELECT rate_limit_per_minute FROM integration_connections
                WHERE supplier_id = ? AND id = ? AND status = 'active' FOR UPDATE");
            $connection->execute([$supplierId, $connectionId]);
            $rate = $connection->fetchColumn();
            if ($rate === false) {
                $pdo->commit();
                return null;
            }
            $used = $pdo->prepare('SELECT COUNT(*) FROM integration_outbox WHERE connection_id = ?
                AND last_attempt_at >= DATE_SUB(NOW(6), INTERVAL 1 MINUTE)');
            $used->execute([$connectionId]);
            if ((int) $used->fetchColumn() >= (int) $rate) {
                $pdo->commit();
                return null;
            }
            $stmt = $pdo->prepare("SELECT candidate.* FROM integration_outbox candidate WHERE candidate.supplier_id = ? AND candidate.connection_id = ?
                AND candidate.status IN ('pending','retry','processing') AND candidate.available_at <= NOW(6)
                AND (candidate.status <> 'processing' OR candidate.lease_until < NOW(6))
                AND NOT EXISTS (SELECT 1 FROM integration_outbox earlier
                    WHERE earlier.connection_id = candidate.connection_id
                      AND earlier.entity_type = candidate.entity_type AND earlier.entity_id = candidate.entity_id
                      AND earlier.aggregate_version < candidate.aggregate_version AND earlier.status <> 'delivered')
                ORDER BY candidate.id LIMIT 1 FOR UPDATE");
            $stmt->execute([$supplierId, $connectionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE integration_outbox SET status = 'processing', attempts = attempts + 1, last_attempt_at = NOW(6),
                lease_token = ?, lease_until = DATE_ADD(NOW(6), INTERVAL ? SECOND), last_error_code = NULL WHERE id = ?")
                ->execute([$token, max(5, min(3600, $leaseSeconds)), $row['id']]);
            $pdo->commit();
            $row['lease_token'] = $token;
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['payload'] = json_decode($row['payload_json'], true, 128, JSON_THROW_ON_ERROR);
            unset($row['payload_json']);
            return $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delivered(int $supplierId, int $id, string $token): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE integration_outbox SET status = 'delivered', delivered_at = NOW(6),
            lease_token = NULL, lease_until = NULL WHERE supplier_id = ? AND id = ? AND status = 'processing' AND lease_token = ?");
        $stmt->execute([$supplierId, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    public function failed(int $supplierId, int $id, string $token, string $errorCode): bool
    {
        if (preg_match('/^[a-z0-9_]{1,100}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('Neplatný kód chyby.');
        }
        $stmt = $this->db->pdo()->prepare("UPDATE integration_outbox SET
            status = IF(attempts >= ?, 'dead_letter', 'retry'),
            available_at = DATE_ADD(NOW(6), INTERVAL LEAST(3600, POW(2, attempts) + MOD(id, 17)) SECOND),
            lease_token = NULL, lease_until = NULL, last_error_code = ?
            WHERE supplier_id = ? AND id = ? AND status = 'processing' AND lease_token = ?");
        $stmt->execute([self::MAX_ATTEMPTS, $errorCode, $supplierId, $id, $token]);
        return $stmt->rowCount() === 1;
    }

    public function retryDeadLetter(int $supplierId, int $connectionId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare("UPDATE integration_outbox SET status = 'retry', attempts = 0,
            available_at = NOW(6), last_error_code = NULL WHERE supplier_id = ? AND connection_id = ?
            AND id = ? AND status = 'dead_letter' AND payload_redacted_at IS NULL
            AND SHA2(payload_json, 256) = payload_sha256");
        $stmt->execute([$supplierId, $connectionId, $id]);
        return $stmt->rowCount() === 1;
    }
}

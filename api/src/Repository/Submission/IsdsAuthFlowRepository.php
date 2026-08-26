<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Submission\Channel\Isds\IsdsAuthFlowStore;
use PDO;

final readonly class IsdsAuthFlowRepository implements IsdsAuthFlowStore
{
    private const TABLE = 'submission_isds_auth_flows';

    public function __construct(private Connection $db) {}

    public function create(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
        string $payloadCiphertext,
        int $ttlSeconds,
        int $maxAttempts,
    ): void {
        $this->assertAvailable();
        $this->eraseExpiredSecrets();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (token_hash, supplier_id, user_id, environment, flow_type,
                 payload_ciphertext, max_attempts, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $tokenHash,
            $supplierId,
            $userId,
            $environment,
            $flowType,
            $payloadCiphertext,
            $maxAttempts,
            $expiresAt,
        ]);
    }

    public function claim(
        string $tokenHash,
        int $supplierId,
        int $userId,
        string $environment,
        string $flowType,
    ): ?array {
        $this->assertAvailable();
        $this->eraseExpiredSecrets();
        $statement = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET status = \'processing\', attempts = attempts + 1
              WHERE token_hash = ? AND supplier_id = ? AND user_id = ?
                AND environment = ? AND flow_type = ? AND status = \'pending\'
                AND expires_at >= UTC_TIMESTAMP() AND attempts < max_attempts'
        );
        $statement->execute([$tokenHash, $supplierId, $userId, $environment, $flowType]);
        if ($statement->rowCount() !== 1) {
            return null;
        }

        $select = $this->db->pdo()->prepare(
            'SELECT id, payload_ciphertext, attempts, max_attempts
               FROM ' . self::TABLE . '
              WHERE token_hash = ? AND supplier_id = ? AND user_id = ?
                AND environment = ? AND flow_type = ? AND status = \'processing\''
        );
        $select->execute([$tokenHash, $supplierId, $userId, $environment, $flowType]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_string($row['payload_ciphertext'] ?? null)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'payload_ciphertext' => $row['payload_ciphertext'],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
        ];
    }

    public function release(int $id): void
    {
        $this->assertAvailable();
        $statement = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET status = CASE
                        WHEN attempts >= max_attempts OR expires_at < UTC_TIMESTAMP() THEN \'blocked\'
                        ELSE \'pending\'
                    END,
                    payload_ciphertext = CASE
                        WHEN attempts >= max_attempts OR expires_at < UTC_TIMESTAMP() THEN NULL
                        ELSE payload_ciphertext
                    END
              WHERE id = ? AND status = \'processing\''
        );
        $statement->execute([$id]);
    }

    public function consume(int $id): bool
    {
        $this->assertAvailable();
        $statement = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET status = \'consumed\', consumed_at = UTC_TIMESTAMP(), payload_ciphertext = NULL
              WHERE id = ? AND status = \'processing\''
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    private function assertAvailable(): void
    {
        if (!$this->db->hasTable(self::TABLE)) {
            throw new \DomainException('Jednorázové ISDS relace nejsou k dispozici (chybí migrace 1542).');
        }
    }

    private function eraseExpiredSecrets(): void
    {
        $this->db->pdo()->exec(
            'UPDATE ' . self::TABLE . '
                SET status = \'blocked\', payload_ciphertext = NULL
              WHERE (status = \'pending\' AND expires_at < UTC_TIMESTAMP())
                 OR (status = \'processing\' AND expires_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE)'
        );
    }
}
